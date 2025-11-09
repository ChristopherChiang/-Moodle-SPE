# sentiment_api.py
# SPE Sentiment API (Ed25519-secured) — enhanced analysis version

from __future__ import annotations
from typing import Optional, Dict, List, Tuple
import os
import re
import json
import logging

from fastapi import FastAPI, Header, HTTPException, Request, Response
from fastapi.middleware.cors import CORSMiddleware
from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer
import uvicorn

from ca_helpers import verify_client_request, sign_response

# =========================
# Thresholds & Parameters
# =========================
SCORE_MIN_DEFAULT = 5
SCORE_MAX_DEFAULT = 25

DISPARITY_LOW_MIN = 5
DISPARITY_LOW_MAX = 10
DISPARITY_HIGH_MIN = 20
DISPARITY_HIGH_MAX = 25

# Use COMPOUND thresholds directly (VADER-style)
POS_C = +0.25
NEG_C = -0.25

# Negation window (# of word tokens after negator to tag)
NEG_WINDOW = 3

# =========================
# Configuration & FastAPI
# =========================
API_TOKEN = os.environ.get("sentiment_token", "").strip()
BIND_HOST = os.environ.get("SPE_BIND", "127.0.0.1")
PORT = int(os.environ.get("PORT", "8000"))
RELOAD = os.environ.get("RELOAD", "").lower() == "true"

app = FastAPI(title="SPE Sentiment API (Ed25519-secured)", version="2.3.2")

# IMPORTANT: pass the class + kwargs (do NOT instantiate CORSMiddleware yourself)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

analyzer = SentimentIntensityAnalyzer()

# =========================
# Lexicon & Phrase Tuning
# =========================

CUSTOM_WEAK_NEG = {
    "concern": -2.6, "concerns": -2.6, "issue": -2.6, "issues": -2.6,
    "problem": -2.9, "problems": -2.9, "challenge": -2.6, "challenges": -2.6,
    "difficult": -2.1, "difficulty": -2.1, "difficulties": -2.1,
    "delay": -2.5, "delayed": -2.5, "late": -2.5, "inconsistent": -2.6,
    "inconsistency": -2.6, "struggle": -2.7, "struggles": -2.7,
    "unreliable": -3.0, "unresponsive": -3.0, "lack": -2.4, "lacking": -2.4,
    "insufficient": -2.6, "inflexible": -2.8, "dominating": -2.8,
    "dominant": -2.6, "needs": -1.2, "improvement": -1.2, "improve": -1.2,
    "improving": -1.0, "blocking": -2.9, "obstructive": -3.2,
    "conflict": -2.9, "frustrating": -3.0, "frustration": -3.0
}
analyzer.lexicon.update(CUSTOM_WEAK_NEG)

# Domain phrases & patterns (MERGED)
PHRASE_PATTERNS: Dict[str, str] = {
    # General negatives/risks
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

    # Effort / contribution extremes
    r"\bdid\s+most\s+of\s+the\s+work(\s+(for|within)\s+(the\s+)?(team|group))?\b": "did_most_of_work",
    r"\bi\s+did\s+a\s+lot\s+(for|of)\s+(the\s+)?team\b": "did_a_lot_for_team",
    r"\bgood\s+job\b": "good_job",
    r"\bdid\s+not\s+do\s+much(\s+at\s+all)?\b": "did_not_do_much",
    r"\bdid\s+not\s+contribut(e|ed)\s+at\s+all\b": "did_not_contribute_at_all",

    # MOST-OF variants (from examples)
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?project\b": "did_most_of_project",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?project\b.*\b(coding|code)\b": "did_most_of_project_coding",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?(?:(?:project|group)\s+)?(?:work|tasks?)\b": "did_most_of_work",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?job\b": "did_most_of_job",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?reports?\b": "did_most_of_reports",
    r"\b(does|did)\s+most\s+of\s+(?:the\s+)?presentation\s+slides?\b": "did_most_of_slides",

    # Percentage forms
    r"\b(does|did)\s+only\s+(?:about\s+)?(\d{1,3})\s*%\s+(?:of\s+)?(?:the\s+)?(?:(?:project|group)\s+)?(?:work|tasks?)\b":
        "did_only_pct_work_\\2",
    r"\bonly\s+(?:about\s+)?(\d{1,3})\s*%\s+(?:done|completed|work|project|tasks?)\b":
        "did_only_pct_work_\\1",
    r"\b(does|did)\s+(?:about\s+)?(\d{1,3})\s*%\s+(?:of\s+)?(?:the\s+)?(?:(?:project|group)\s+)?(?:work|tasks?)\b":
        "did_pct_work_\\2",

    # Availability / responsiveness
    r"\balways\s+not\s+available\b": "always_not_available",
    r"\b(always\s+)?un(contactable|contactable)\b": "always_uncontactable",
    r"\b(always\s+)?(unavailable|not\s+available)\b": "always_unavailable",
    r"\b(absent|no[-\s]?show|no\s+shows?)\b": "absenteeism",
    r"\bnon[-\s]?responsive\b": "unresponsive",
    r"\b(always|often)\s+late\b": "often_late",

    # Positive contributions
    r"\bcontribut(?:ed|es|ing)?\s+in\s+(?:planning\s+)?meetings?\b": "contributed_in_meetings",
}

PHRASE_LEXICON: Dict[str, float] = {
    "create_challenges": -3.1, "dominate_discussions": -3.0,
    "rush_through_tasks": -2.7, "minor_misunderstandings": -1.8,
    "inconsistencies": -2.4, "strong_opinions": -1.4,
    "time_mgmt_could_improve": -2.6, "delays_in_completing": -2.8,
    "affects_overall_progress": -2.6, "needs_improvement": -2.9,
    "room_for_improvement": -2.2,

    "did_most_of_work": +3.1, "did_a_lot_for_team": +2.8, "good_job": +2.3,
    "did_not_do_much": -3.2, "did_not_contribute_at_all": -3.6,

    "did_most_of_job": +2.8,
    "did_most_of_reports": +2.4,
    "did_most_of_slides": +2.4,

    "always_not_available": -3.2,
    "always_uncontactable": -3.4,
    "always_unavailable": -3.0,
    "absenteeism": -3.1,
    "unresponsive": -3.0,
    "often_late": -2.6,

    "contributed_in_meetings": +1.6,

    "did_most_of_project": +2.2,
    "did_most_of_project_coding": +2.6,
}
analyzer.lexicon.update(PHRASE_LEXICON)

# Tiny general nudge so "most of ... work" contexts aren't 0.000
analyzer.lexicon.update({"most_of": +0.4})

# Dynamic token weights for % tokens
def _apply_dynamic_pct_tokens(text: str) -> None:
    for m in re.finditer(r"\bdid_only_pct_work_(\d{1,3})\b", text):
        pct = max(0, min(100, int(m.group(1))))
        penalty = -1.4 - 2.2 * (100 - pct) / 100.0
        analyzer.lexicon[m.group(0)] = penalty

    for m in re.finditer(r"\bdid_pct_work_(\d{1,3})\b", text):
        pct = max(0, min(100, int(m.group(1))))
        if pct < 70:
            penalty = -0.8 - 2.2 * (70 - pct) / 70.0
            analyzer.lexicon[m.group(0)] = penalty
        else:
            boost = +0.6 * (pct - 70) / 30.0
            analyzer.lexicon[m.group(0)] = min(+0.6, boost)

# Intensifier spam control
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
            out.append(w)
            i += 1
    return "".join(out)

# Teach VADER some NOT_* negatives
analyzer.lexicon.update({
    "NOT_good": -1.8, "NOT_helpful": -2.0, "NOT_available": -2.4, "NOT_responsive": -2.6,
})

# Phrase preprocessing (regex → tokens) + dynamic % lexicon
def preprocess_phrases(text: str) -> str:
    for pat, token in PHRASE_PATTERNS.items():
        text = re.sub(pat, token, text, flags=re.IGNORECASE)
    _apply_dynamic_pct_tokens(text)
    return text

# Toxic words (hard negative override)
TOXIC_RE = re.compile(
    r"\b(dumbass|idiot|stupid|moron|useless|garbage|trash|loser|worthless|asshole|bitch|fuck|shit|hate|toxic)\b",
    re.IGNORECASE
)
def is_toxic(text: str) -> bool:
    return bool(TOXIC_RE.search(text or ""))

# Contrast handling
CONTRAST_RE = re.compile(r"\b(but|however|although|though|yet|while|despite)\b", re.IGNORECASE)
NEG_TAIL_RE = re.compile(r"\b(challenge|problem|concern|delay|inflexible|issue|struggle)\b", re.IGNORECASE)

def split_contrast(text: str):
    m = CONTRAST_RE.search(text)
    if not m: return text, None, None
    return text[:m.start()].strip(), m.group(0), text[m.end():].strip()

def contrast_tail_adjustment(text: str, base: float) -> float:
    _, cue, tail = split_contrast(text)
    if not tail:
        return base
    tscore = analyzer.polarity_scores(tail)["compound"]
    negs = len(list(NEG_TAIL_RE.finditer(tail)))
    if negs >= 3:
        return 0.97 * min(tscore, -0.2) + 0.03 * base
    if negs >= 1 or tscore < -0.05:
        return 0.95 * tscore + 0.05 * base
    return base

# Front-keeps-control contrast
CRITICAL_FRONT_TOKENS = [
    "always_not_available", "always_unavailable", "always_uncontactable",
    "did_not_do_much", "did_not_contribute_at_all",
    "unresponsive", "absenteeism", "often_late"
]
def contrast_rebalance(text: str, base: float) -> float:
    front, cue, tail = split_contrast(text)
    if not tail:
        return base
    fs = analyzer.polarity_scores(front)["compound"]
    ts = analyzer.polarity_scores(tail)["compound"]
    front_has_crit = any(tok in front for tok in CRITICAL_FRONT_TOKENS)
    if front_has_crit and fs <= -0.35:
        return 0.70 * fs + 0.30 * min(ts, 0.20)
    return contrast_tail_adjustment(text, base)

# Sentence scoring & "worst sentence" rescue
SPLIT_SENT = re.compile(r"(?<=[\.\?!])\s+")
def sentence_scores(text: str) -> Tuple[List[float], float, float]:
    parts = [p.strip() for p in SPLIT_SENT.split(text) if p.strip()]
    if not parts:
        return [], 0.0, 0.0
    comps = [analyzer.polarity_scores(p)["compound"] for p in parts]
    return comps, sum(comps) / len(comps), min(comps)

# Mapping helpers
def polarity_from_compound(c: float) -> float:
    return max(0.0, min(1.0, (c + 1.0) / 2.0))

def label_from_compound(c: float) -> str:
    if c >= POS_C: return "positive"
    if c <= NEG_C: return "negative"
    return "neutral"

# Disparity (v1 & v2)
def evaluate_disparity(label: str, score_total, smin, smax):
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

def evaluate_disparity_v2(label: str, comp: float, min_c: float, score_total, smin, smax):
    disp, reason, confirm = evaluate_disparity(label, score_total, smin, smax)
    extra = None
    if score_total is not None:
        try:
            st = float(score_total)
            high_score = st >= DISPARITY_HIGH_MIN
            low_score  = st <= DISPARITY_LOW_MAX
            if high_score and min_c <= -0.50:
                disp = True; confirm = True
                extra = f"High total {st:g} but contains strongly negative sentence (min={min_c:+.3f})."
            if low_score and comp >= +0.45:
                disp = True; confirm = True
                extra = f"Low total {st:g} but overall sentiment is positive (compound={comp:+.3f})."
        except Exception:
            pass
    if extra:
        reason = (reason + " " if reason else "") + extra
    return disp, reason, confirm

# Optional small bias based on target (self vs peer)
def target_bias(label: str, text: str, target: Optional[str]) -> float:
    if not target: return 0.0
    txt = text.lower() + " "
    if target == "peer" and any(p in txt for p in (" he ", " she ", " they ", " him ", " her ")):
        return -0.05 if label == "positive" else 0.0
    if target == "self" and any(p in txt for p in (" i ", " my ", " me ")):
        return +0.03 if label == "positive" else 0.0
    return 0.0

# =========================
# Main analysis function
# =========================
def analyze_text_full(text: str,
                      score_total=None,
                      score_min=None,
                      score_max=None,
                      target: Optional[str] = None):
    tx = (text or "").strip()
    wc = len(re.findall(r"\b\w+\b", tx))
    cc = len(tx)
    smin = float(score_min or SCORE_MIN_DEFAULT)
    smax = float(score_max or SCORE_MAX_DEFAULT)

    if not tx:
        disp, reason, confirm = evaluate_disparity("neutral", score_total, smin, smax)
        return {
            "label": "neutral", "score": 0.5, "confidence": 0.5, "compound": 0.0,
            "pos": 0.0, "neu": 1.0, "neg": 0.0, "toxic": False,
            "word_count": wc, "char_count": cc,
            "sentences": {"count": 0, "avg_compound": 0.0, "min_compound": 0.0},
            "matched_tokens": [], "negation_used": False,
            "label_source": "hybrid_vader_rules",
            "disparity": disp, "disparity_reason": reason, "suggest_confirm": confirm
        }

    # Preprocess
    tx = cap_intensifier_runs(tx, max_repeats=2)
    text_prep = preprocess_phrases(tx)
    text_prep = widen_negation_scope(text_prep)

    # Sentence-level diagnostics
    comps, avg_c, min_c = sentence_scores(text_prep)

    # Whole-text + contrast rebalance
    s_all = analyzer.polarity_scores(text_prep)
    comp = contrast_rebalance(text_prep, s_all["compound"])

    # If worst sentence is very negative, blend it in to avoid masking
    if min_c <= -0.45 and comp > -0.25:
        comp = 0.65 * comp + 0.35 * min_c

    label = label_from_compound(comp)
    toxic = is_toxic(tx)
    if toxic and comp > -0.6:
        comp, label = -0.6, "toxic"

    # Small target-aware bias (optional)
    comp += target_bias(label, text_prep, target)
    label = label_from_compound(comp)

    conf = polarity_from_compound(comp)

    # Matched domain tokens (top N for debug)
    matched = [t for t in PHRASE_LEXICON.keys() if t in text_prep][:15]
    negation_used = bool(re.search(r"\bNOT_\w+", text_prep))

    # Disparity v2
    disp, reason, confirm = evaluate_disparity_v2(label, comp, min_c, score_total, smin, smax)

    return {
        "label": label, "score": conf, "confidence": conf, "compound": comp,
        "pos": s_all["pos"], "neu": s_all["neu"], "neg": s_all["neg"],
        "toxic": toxic,
        "word_count": wc, "char_count": cc,
        "sentences": {"count": len(comps), "avg_compound": avg_c, "min_compound": min_c},
        "matched_tokens": matched, "negation_used": negation_used,
        "label_source": "hybrid_vader_rules",
        "disparity": disp, "disparity_reason": reason, "suggest_confirm": confirm
    }

# =========================
# FastAPI Routes
# =========================
@app.post("/analyze")
async def analyze_unified(
    request: Request,
    payload: dict,
    x_api_token: Optional[str] = Header(default=None),
    x_spe_client_cert: Optional[str] = Header(default=None),
    x_spe_client_certsig: Optional[str] = Header(default=None),
    x_spe_client_sig: Optional[str] = Header(default=None),
):
    raw_body = (await request.body()).decode()
    try:
        verify_client_request("/analyze", raw_body, {
            "X-SPE-Client-Cert":    x_spe_client_cert or "",
            "X-SPE-Client-CertSig": x_spe_client_certsig or "",
            "X-SPE-Client-Sig":     x_spe_client_sig or "",
        })
    except Exception as e:
        raise HTTPException(status_code=403, detail=str(e))

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

    if API_TOKEN and (x_api_token or "").strip() != API_TOKEN:
        body_out = {"ok": False, "results": []}
        body_str = json.dumps(body_out, separators=(',', ':'), ensure_ascii=False)
        signed_headers = sign_response("/analyze", body_str)
        return Response(content=body_str, media_type="application/json", headers=signed_headers)

    if "items" in payload and isinstance(payload["items"], list):
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

# =========================
# Entrypoint
# =========================
if __name__ == "__main__":
    uvicorn.run("sentiment_api:app", host=BIND_HOST, port=PORT, reload=RELOAD, access_log=False)
