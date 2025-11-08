# Import statements
from __future__ import annotations
from typing import Optional, Dict
import os
import re
import json, logging
from fastapi import FastAPI, Header, HTTPException, Request, Response
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer
import uvicorn
from ca_helpers import verify_client_request, sign_response

# Thresholds
SCORE_MIN_DEFAULT = 5
SCORE_MAX_DEFAULT = 25

DISPARITY_LOW_MIN = 5
DISPARITY_LOW_MAX = 10
DISPARITY_HIGH_MIN = 20
DISPARITY_HIGH_MAX = 25

POS_THR = 0.62
NEG_THR = 0.44

# Configuration
API_TOKEN = os.environ.get("sentiment_token", "").strip()
BIND_HOST = os.environ.get("SPE_BIND", "127.0.0.1")
PORT = int(os.environ.get("PORT", "8000"))
RELOAD = os.environ.get("RELOAD", "").lower() == "true"

# Setup FastAPI
app = FastAPI(title="SPE Sentiment API (Ed25519-secured)", version="2.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],          
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

analyzer = SentimentIntensityAnalyzer()

# Tunning of lexicon
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

PHRASE_PATTERNS = {
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
}
PHRASE_LEXICON = {
    "create_challenges": -3.1, "dominate_discussions": -3.0,
    "rush_through_tasks": -2.7, "minor_misunderstandings": -1.8,
    "inconsistencies": -2.4, "strong_opinions": -1.4,
    "time_mgmt_could_improve": -2.6, "delays_in_completing": -2.8,
    "affects_overall_progress": -2.6, "needs_improvement": -2.9,
    "room_for_improvement": -2.2,     "did_most_of_work":  +3.1,     # very positive
    "did_a_lot_for_team": +2.8,    # positive
    "good_job": +2.3,              # positive booster
    "did_not_do_much": -3.2,       # clearly negative
    "did_not_contribute_at_all": -3.6,  # very negative
}
analyzer.lexicon.update(PHRASE_LEXICON)

def preprocess_phrases(text: str) -> str:
    import re as _re
    for pat, token in PHRASE_PATTERNS.items():
        text = _re.sub(pat, token, text, flags=_re.IGNORECASE)
    return text

# Toxic and contrast handling
import re
TOXIC_RE = re.compile(
    r"\b(dumbass|idiot|stupid|moron|useless|garbage|trash|loser|worthless|asshole|bitch|fuck|shit|hate|toxic)\b",
    re.IGNORECASE
)
def is_toxic(text: str) -> bool:
    return bool(TOXIC_RE.search(text or ""))

CONTRAST_RE = re.compile(r"\b(but|however|although|though|yet|while|despite)\b", re.IGNORECASE)
NEG_TAIL_RE = re.compile(r"\b(challenge|problem|concern|delay|inflexible|issue|struggle)\b", re.IGNORECASE)

def split_contrast(text: str):
    m = CONTRAST_RE.search(text)
    if not m:
        return text, None, None
    return text[:m.start()].strip(), m.group(0), text[m.end():].strip()

def contrast_tail_adjustment(text: str, base: float):
    _, cue, tail = split_contrast(text)
    if not tail: return base
    tscore = analyzer.polarity_scores(tail)["compound"]
    negs = len(list(NEG_TAIL_RE.finditer(tail)))
    if negs >= 3: return 0.97 * min(tscore, -0.2) + 0.03 * base
    if negs >= 1 or tscore < -0.05: return 0.95 * tscore + 0.05 * base
    return base

# Sentiment analysis
def polarity_from_compound(c: float) -> float:
    return max(0.0, min(1.0, (c + 1.0) / 2.0))

def label_from_compound(c: float) -> str:
    p = polarity_from_compound(c)
    if p >= POS_THR: return "positive"
    if p <= NEG_THR: return "negative"
    return "neutral"

def evaluate_disparity(label: str, score_total, smin, smax):
    if score_total is None:
        return False, None, False
    try:
        st = float(score_total)
    except:
        return False, None, False

    # Low total but positive comment
    if DISPARITY_LOW_MIN <= st <= DISPARITY_LOW_MAX and label == "positive":
        return True, (
            f"Total score {st:g} is low "
            f"and the comment reads {label}."
        ), True

    # High total but negative/toxic comment
    if DISPARITY_HIGH_MIN <= st <= DISPARITY_HIGH_MAX and label in ("negative", "toxic"):
        return True, (
            f"Total score {st:g} is high "
            f"and the comment reads {label}."
        ), True

    return False, None, False



def analyze_text_full(text: str, score_total=None, score_min=None, score_max=None):
    tx = (text or "").strip()
    import re as _re
    wc = len(_re.findall(r"\b\w+\b", tx))
    cc = len(tx)
    smin = float(score_min or SCORE_MIN_DEFAULT)
    smax = float(score_max or SCORE_MAX_DEFAULT)

    if not tx:
        disp, reason, confirm = evaluate_disparity("neutral", score_total, smin, smax)
        return {
            "label": "neutral", "score": 0.5, "confidence": 0.5, "compound": 0.0,
            "pos": 0.0, "neu": 1.0, "neg": 0.0, "toxic": False,
            "word_count": wc, "char_count": cc,
            "disparity": disp, "disparity_reason": reason, "suggest_confirm": confirm
        }

    text_prep = preprocess_phrases(tx)
    s_all = analyzer.polarity_scores(text_prep)
    comp = contrast_tail_adjustment(text_prep, s_all["compound"])
    label = label_from_compound(comp)
    toxic = is_toxic(tx)
    if toxic and comp > -0.6: comp, label = -0.6, "toxic"
    conf = polarity_from_compound(comp)
    disp, reason, confirm = evaluate_disparity(label, score_total, smin, smax)
    return {
        "label": label, "score": conf, "confidence": conf, "compound": comp,
        "pos": s_all["pos"], "neu": s_all["neu"], "neg": s_all["neg"],
        "toxic": toxic, "word_count": wc, "char_count": cc,
        "disparity": disp, "disparity_reason": reason, "suggest_confirm": confirm
    }

# Routes
@app.post("/analyze")
async def analyze_unified(
    request: Request,
    payload: dict,
    x_api_token: Optional[str] = Header(default=None),
    x_spe_client_cert: Optional[str] = Header(default=None),
    x_spe_client_certsig: Optional[str] = Header(default=None),
    x_spe_client_sig: Optional[str] = Header(default=None),
):
    # Get raw bytes exactly as sent by client
    raw_body = (await request.body()).decode()

    # Verify client request using Ed25519 certificate flow
    try:
        verify_client_request("/analyze", raw_body, {
            "X-SPE-Client-Cert":    x_spe_client_cert or "",
            "X-SPE-Client-CertSig": x_spe_client_certsig or "",
            "X-SPE-Client-Sig":     x_spe_client_sig or "",
        })
    except Exception as e:
        raise HTTPException(status_code=403, detail=str(e))

    # One-time handshake log
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

    # Token gate (always enforce when API_TOKEN is configured)
    if API_TOKEN and (x_api_token or "").strip() != API_TOKEN:
        body_out = {"ok": False, "results": []}
        body_str = json.dumps(body_out, separators=(',', ':'), ensure_ascii=False)
        signed_headers = sign_response("/analyze", body_str)
        return Response(content=body_str, media_type="application/json", headers=signed_headers)

    # ---- Batch mode only ----
    if "items" in payload and isinstance(payload["items"], list):
        results = []
        for it in payload["items"][:2000]:
            r = analyze_text_full(
                it.get("text"),
                it.get("score_total"),
                it.get("score_min"),
                it.get("score_max"),
            )
            if "id" in it:
                r["id"] = it["id"]
            results.append(r)

        body_out = {"ok": True, "results": results}
        body_str = json.dumps(body_out, separators=(',', ':'), ensure_ascii=False)
        signed_headers = sign_response("/analyze", body_str)
        return Response(content=body_str, media_type="application/json", headers=signed_headers)

    # If bad payload (no items)
    raise HTTPException(status_code=422, detail="Batch mode only. Provide 'items': [{...}, ...].")

if __name__ == "__main__":
    uvicorn.run("sentiment_api:app", host=BIND_HOST, port=PORT, reload=RELOAD, access_log=False)


