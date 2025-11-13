from __future__ import annotations
from typing import Optional, Dict, List, Tuple
import os
import re
import json
import logging

from fastapi import FastAPI, Header, HTTPException, Request, Response
from fastapi.middleware.cors import CORSMiddleware
import uvicorn

from ca_helpers import verify_client_request, sign_response

# Suppress HuggingFace warnings
class _DropPoolerWarning(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        msg = record.getMessage()
        return "were not used when initializing" not in msg

logging.getLogger("transformers.modeling_utils").addFilter(_DropPoolerWarning())

# Thresholds
SCORE_MIN_DEFAULT = 5
SCORE_MAX_DEFAULT = 25

DISPARITY_LOW_MIN = 5
DISPARITY_LOW_MAX = 10
DISPARITY_HIGH_MIN = 20
DISPARITY_HIGH_MAX = 25

POS_THR = 0.62
NEG_THR = 0.44

NEG_WINDOW = 3

# HuggingFace model
HF_MODEL_NAME = os.environ.get(
    "SPE_HF_MODEL",
    "cardiffnlp/twitter-roberta-base-sentiment-latest"  
)
HF_DEVICE = os.environ.get("SPE_HF_DEVICE", "cpu")        
HF_MAX_LEN = int(os.environ.get("SPE_HF_MAXLEN", "256"))  

# Configuration
API_TOKEN = os.environ.get("sentiment_token", "").strip()
BIND_HOST = os.environ.get("SPE_BIND", "127.0.0.1")
PORT = int(os.environ.get("PORT", "8000"))
RELOAD = os.environ.get("RELOAD", "").lower() == "true"

app = FastAPI(title="SPE Sentiment API (Ed25519-secured)", version="4.0.0")

# CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Roberta model load
_roberta = None
_roberta_labels = ["negative", "neutral", "positive"]
try:
    from transformers import pipeline, AutoTokenizer, AutoModelForSequenceClassification
    import torch 

    _tokenizer = AutoTokenizer.from_pretrained(HF_MODEL_NAME)
    _model = AutoModelForSequenceClassification.from_pretrained(HF_MODEL_NAME)
    _roberta = pipeline(
        "sentiment-analysis",
        model=_model,
        tokenizer=_tokenizer,
        device=0 if HF_DEVICE == "cuda" else -1,
        truncation=True,
        max_length=HF_MAX_LEN,
        top_k=None,
        return_all_scores=True,
    )
except Exception as e:
    logging.getLogger("uvicorn").error("RoBERTa failed to load (%s).", str(e))
    _roberta = None 

# Phrase patterns
PHRASE_PATTERNS: Dict[str, str] = {
    r"\b(can\s+)?create\s+challenges\b": "create_challenges",
    r"\b(dominate|dominates|dominating)\s+discussions?\b": "dominate_discussions",
    r"\brush\s+through\s+tasks?\b": "rush_through_tasks",
    r"\bminor\s+misunderstandings?\b": "minor_misunderstandings",
    r"\b(in)?consistenc(y|ies)\b": "inconsistencies",
    r"\bstrong\s+opinions\b": "strong_opinions",
    r"\btime\s+management\s+could\s+improve\b": "time_mgmt_could_improve",
    r"\bdelays?\s+in\s+completing\b": "delays_in_completing",
    r"\baffect(s|ed)?\s+overall\s+progress\b": "affects_overall_progress",
    r"\bneeds?\s+improvement\b": "needs_improvement",
    r"\broom\s+for\s+improvement\b": "room_for_improvement",
    r"\bdid\s+most\s+of\s+the\s+work(\s+(for|within)\s+(the\s+)?(team|group))?\b": "did_most_of_work",
    r"\bi\s+did\s+a\s+lot\s+(for|of)\s+(the\s+)?team\b": "did_a_lot_for_team",
    r"\bgood\s+job\b": "good_job",
    r"\bdid\s+not\s+do\s+much(\s+at\s+all)?\b": "did_not_do_much",
    r"\bdid\s+not\s+contribut(e|ed)\s+at\s+all\b": "did_not_contribute_at_all",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?project\b": "did_most_of_project",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?project\b.*\b(coding|code)\b": "did_most_of_project_coding",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?(?:(?:project|group)\s+)?(?:work|tasks?)\b": "did_most_of_work",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?job\b": "did_most_of_job",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?reports?\b": "did_most_of_reports",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?presentation\s+slides?\b": "did_most_of_slides",
    r"\b(does|did)\s+only\s+(?:about\s+)?(\d{1,3})\s*%\s+(?:of\s+)?(?:the\s+)?(?:(?:project|group)\s+)?(?:work|tasks?)\b": "did_only_pct_work_\\2",
    r"\bonly\s+(?:about\s+)?(\d{1,3})\s*%\s+(?:done|completed|work|project|tasks?)\b": "did_only_pct_work_\\1",
    r"\b(does|did)\s+(?:about\s+)?(\d{1,3})\s*%\s+(?:of\s+)?(?:the\s+)?(?:(?:project|group)\s+)?(?:work|tasks?)\b": "did_pct_work_\\2",
    r"\balways\s+not\s+available\b": "always_not_available",
    r"\b(always\s+)?un(contactable|contactable)\b": "always_uncontactable",
    r"\b(always\s+)?(unavailable|not\s+available)\b": "always_unavailable",
    r"\b(absent|no[-\s]?show|no\s+shows?)\b": "absenteeism",
    r"\bnon[-\s]?responsive\b": "unresponsive",
    r"\b(always|often)\s+late\b": "often_late",
    r"\bcontribut(?:ed|es|ing)?\s+in\s+(?:planning\s+)?meetings?\b": "contributed_in_meetings",
}

# Functions for dynamic percentage tokens
def _apply_dynamic_pct_tokens(text: str) -> List[str]:
    matched = []
    for m in re.finditer(r"\bdid_only_pct_work_(\d{1,3})\b", text):
        matched.append(m.group(0))
    for m in re.finditer(r"\bdid_pct_work_(\d{1,3})\b", text):
        matched.append(m.group(0))
    return matched

# Intensifier 
INTENSIFIER_RE = re.compile(r"\b(very|extremely|super|really)\b", re.IGNORECASE)
def cap_intensifier_runs(text: str, max_repeats: int = 2) -> str:
    tokens = text.split()
    out: List[str] = []
    run = 0
    last_int = False
    for t in tokens:
        if INTENSIFIER_RE.fullmatch(t):
            run = run + 1 if last_int else 1
            last_int = True
            if run <= max_repeats:
                out.append(t)
        else:
            last_int = False
            run = 0
            out.append(t)
    return " ".join(out)

# Negation scope widening
NEGATORS = re.compile(r"^(?:not|never|no|rarely|hardly|seldom|scarcely|barely)$", re.IGNORECASE)
def widen_negation_scope(text: str) -> str:
    words = re.findall(r"\w+|\W+", text)
    out: List[str] = []
    i = 0
    while i < len(words):
        w = words[i]
        if re.fullmatch(r"\w+", w) and NEGATORS.fullmatch(w):
            out.append(w)
            j, seen = i + 1, 0
            while j < len(words) and seen < NEG_WINDOW:
                if re.fullmatch(r"\w+", words[j]):
                    words[j] = "NOT_" + words[j]
                    seen += 1
                j += 1
            i += 1
        else:
            out.append(w); i += 1
    return "".join(out)

# Phrase preprocessing
def preprocess_phrases(text: str) -> Tuple[str, List[str]]:
    for pat, token in PHRASE_PATTERNS.items():
        text = re.sub(pat, token, text, flags=re.IGNORECASE)
    dyn = _apply_dynamic_pct_tokens(text)
    return text, dyn

# Toxic words
TOXIC_RE = re.compile(
    r"\b(dumbass|idiot|stupid|moron|useless|garbage|trash|loser|worthless|asshole|bitch|fuck|shit|hate|toxic|fucker)\b",
    re.IGNORECASE)
def is_toxic(text: str) -> bool:
    return bool(TOXIC_RE.search(text or ""))

# Contrast handling
CONTRAST_RE = re.compile(r"\b(but|however|although|though|yet|while|despite)\b", re.IGNORECASE)
def split_contrast(text: str):
    m = CONTRAST_RE.search(text)
    if not m: return text, None, None
    return text[:m.start()].strip(), m.group(0), text[m.end():].strip()

# Sentence splitting
SPLIT_SENT = re.compile(r"(?<=[\.\?!])\s+")

# Helpers
def roberta_probs(text: str) -> Dict[str, float]:
    """Return probabilities dict for negative/neutral/positive."""
    if _roberta is None:
        raise RuntimeError("RoBERTa model is not loaded.")
    res = _roberta(text[:HF_MAX_LEN])[0]
    probs = {r["label"].lower(): float(r["score"]) for r in res}

    def _norm(lbl):
        l = lbl.lower()
        if "pos" in l or l.endswith("_2"): return "positive"
        if "neg" in l or l.endswith("_0"): return "negative"
        return "neutral"

    probs = {_norm(k): v for k, v in probs.items()}
    for k in ("negative", "neutral", "positive"):
        probs.setdefault(k, 0.0)

    s = sum(probs.values())
    if s > 0:
        for k in probs:
            probs[k] /= s
    return probs

def roberta_compound(text: str) -> Tuple[float, str, Dict[str, float]]:
    probs = roberta_probs(text)
    p_pos = probs.get("positive", 0.0)
    p_neg = probs.get("negative", 0.0)
    comp = p_pos - p_neg
    label = max(probs.items(), key=lambda kv: kv[1])[0]
    return comp, label, probs

def roberta_sentence_scores(text: str) -> Tuple[List[float], float, float]:
    parts = [p.strip() for p in SPLIT_SENT.split(text) if p.strip()]
    if not parts:
        return [], 0.0, 0.0
    comps = []
    for p in parts:
        c, _, _ = roberta_compound(p)
        comps.append(c)
    avg_c = sum(comps) / len(comps)
    min_c = min(comps)
    return comps, avg_c, min_c

def contrast_rebalance_roberta(text: str, base: float) -> float:
    front, cue, tail = split_contrast(text)
    if not tail:
        return base
    cf, _, _ = roberta_compound(front)
    ct, _, _ = roberta_compound(tail)
    if cf <= -0.35:
        return 0.70 * cf + 0.30 * min(ct, 0.20)
    return base

# Disparity evaluation
def evaluate_disparity(label: str, comp: float, min_c: float, score_total, smin, smax):
    if score_total is None:
        return False, None, False
    try:
        st = float(score_total)
    except Exception:
        return False, None, False

    if DISPARITY_LOW_MIN <= st <= DISPARITY_LOW_MAX and label == "positive":
        return True, (f"Total score {st:g} is low and the comment reads {label}."), True

    if DISPARITY_HIGH_MIN <= st <= DISPARITY_HIGH_MAX and label in ("negative", "toxic"):
        return True, (f"Total score {st:g} is high and the comment reads {label}."), True

    return False, None, False

def label_from_score(score01: float) -> str:
    if score01 >= POS_THR: return "positive"
    if score01 <= NEG_THR: return "negative"
    return "neutral"

# Target bias adjustment
def target_bias(label: str, text: str, target: Optional[str]) -> float:
    if not target: return 0.0
    txt = text.lower() + " "
    if target == "peer" and any(p in txt for p in (" he ", " she ", " they ", " him ", " her ")):
        return -0.03 if label == "positive" else 0.0
    if target == "self" and any(p in txt for p in (" i ", " my ", " me ")):
        return +0.02 if label == "positive" else 0.0
    return 0.0

# Main function
def analyze_text_full(text: str,
                      score_total=None,
                      score_min=None,
                      score_max=None,
                      target: Optional[str] = None):
    if _roberta is None:
        raise HTTPException(status_code=503, detail="RoBERTa model not available on server.")

    tx = (text or "").strip()
    wc = len(re.findall(r"\b\w+\b", tx))
    cc = len(tx)
    smin = float(score_min or SCORE_MIN_DEFAULT)
    smax = float(score_max or SCORE_MAX_DEFAULT)

    if not tx:
        disp, reason, confirm = evaluate_disparity("neutral", 0.0, 0.0, score_total, smin, smax)
        return {
            "label": "neutral", "score": 0.5, "compound": 0.0,
            "toxic": False,
            "word_count": wc, "char_count": cc,
            "sentences": {"count": 0, "avg_compound": 0.0, "min_compound": 0.0},
            "matched_tokens": [], "negation_used": False,
            "engine": "roberta_only",
            "roberta": {"compound": 0.0, "label": "neutral", "probs": {"negative":0.0,"neutral":1.0,"positive":0.0}},
            "disparity": disp, "disparity_reason": reason, "suggest_confirm": confirm
        }

    # Preprocess
    tx2 = cap_intensifier_runs(tx, max_repeats=2)
    tx2, dyn_tokens = preprocess_phrases(tx2)
    tx2 = widen_negation_scope(tx2)

    # Sentence scores
    comps, avg_c, min_c = roberta_sentence_scores(tx2)

    # Overall RoBERTa compound
    comp, rob_label, rob_probs = roberta_compound(tx2)
    comp = contrast_rebalance_roberta(tx2, comp)

    # Final score 
    score01 = max(0.0, min(1.0, (comp + 1.0) / 2.0))

    # Toxic override
    toxic = is_toxic(tx)
    label = label_from_score(score01)
    if toxic:
        label = "toxic"
        score01 = 0.0

    # Target bias
    if not toxic:
        comp += target_bias(label, tx2, target)
        score01 = max(0.0, min(1.0, (comp + 1.0) / 2.0))
        label = label_from_score(score01)

    matched = [t for t in set(list(PHRASE_PATTERNS.values()) + dyn_tokens) if t in tx2][:15]
    negation_used = bool(re.search(r"\bNOT_\w+", tx2))

    # Disparity logic 
    disp, reason, confirm = evaluate_disparity(label, comp, min_c, score_total, smin, smax)

    return {
        "label": label,
        "score": score01,
        "compound": comp,
        "toxic": toxic,
        "word_count": wc, "char_count": cc,
        "sentences": {"count": len(comps), "avg_compound": avg_c, "min_compound": min_c},
        "matched_tokens": matched, "negation_used": negation_used,
        "engine": "roberta_only",
        "roberta": {"compound": comp, "label": rob_label, "probs": rob_probs},
        "disparity": disp, "disparity_reason": reason, "suggest_confirm": confirm
    }

# API Endpoint
@app.post("/analyze")
async def analyze_unified(
    request: Request,
    payload: dict,
    x_api_token: Optional[str] = Header(default=None),
    x_spe_client_cert: Optional[str] = Header(default=None),
    x_spe_client_certsig: Optional[str] = Header(default=None),
    x_spe_client_sig: Optional[str] = Header(default=None),
):
    # Verify client request using Ed25519 certificate
    raw_body = (await request.body()).decode()
    try:
        verify_client_request("/analyze", raw_body, {
            "X-SPE-Client-Cert":    x_spe_client_cert or "",
            "X-SPE-Client-CertSig": x_spe_client_certsig or "",
            "X-SPE-Client-Sig":     x_spe_client_sig or "",
        })
    except Exception as e:
        raise HTTPException(status_code=403, detail=str(e))

    # One time handshake
    if not getattr(app.state, "handshake_logged", False):
        plugin_id = "unknown"
        try:
            if x_spe_client_cert:
                plugin_id = json.loads(x_spe_client_cert).get("id", "unknown")
        except Exception:
            pass
        logging.getLogger("uvicorn").info(
            "SPE Ed25519 handshake successful with plugin id=%s", plugin_id
        )
        app.state.handshake_logged = True

    # Token gate 
    if API_TOKEN and (x_api_token or "").strip() != API_TOKEN:
        body_out = {"ok": False, "results": []}
        body_str = json.dumps(body_out, separators=(',', ':'), ensure_ascii=False)
        signed_headers = sign_response("/analyze", body_str)
        return Response(content=body_str, media_type="application/json", headers=signed_headers)

    # Batch mode
    if "items" in payload and isinstance(payload["items"], list):
        if _roberta is None:
            raise HTTPException(status_code=503, detail="RoBERTa model not available on server.")
        results = []
        for it in payload["items"][:2000]:
            r = analyze_text_full(
                it.get("text"),
                it.get("score_total"),
                it.get("score_min"),
                it.get("score_max"),
                it.get("target"), 
            )
            if "id" in it:
                r["id"] = it["id"]
            results.append(r)

        body_out = {"ok": True, "results": results}
        body_str = json.dumps(body_out, separators=(',', ':'), ensure_ascii=False)
        signed_headers = sign_response("/analyze", body_str)
        return Response(content=body_str, media_type="application/json", headers=signed_headers)

    raise HTTPException(status_code=422, detail="Batch mode only. Provide 'items': [{...}, ...].")

# Run the app
if __name__ == "__main__":
    uvicorn.run("sentiment_api:app", host=BIND_HOST, port=PORT, reload=RELOAD, access_log=False)
