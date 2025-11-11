
import base64, json, time
from typing import Dict, Any, Mapping
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey, Ed25519PublicKey
)
from cryptography.exceptions import InvalidSignature

# CA public key and server private key / certificate
CA_PUB_B64       = "iuEnM_VNd8OJn9Mxcz4aEp97gIJMAG41nk2CWxj0XhU"
SERVER_PRIV_B64  = "2SWSDhQL-Pw1ayFGcnwJbaOE52-HYQlEIC9dvRL_IUU"
SERVER_CERT_JSON = '{"id":"spe-api","pubkey":"avOww2r53iLjDacbHGoybcqO10eHw4MUOOaTapiCePA","exp":1793700137,"iss":"SPE-CA"}'
SERVER_CERT_SIG  = "0f75Xhupt6gQYc_mULQHK8JdoUYLxm6wt_u01NYMg726E8-eB4kU2SSGejnwFcDmi8DCMKIgCxJTAlhfGHkACQ"

def b64u_decode(s: str) -> bytes:
    s = s + "=" * (-len(s) % 4)
    return base64.urlsafe_b64decode(s.encode())

def b64u_encode(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()

def verify_signature(pubkey_b64: str, msg: bytes, sig_b64: str) -> bool:
    try:
        Ed25519PublicKey.from_public_bytes(b64u_decode(pubkey_b64)).verify(
            b64u_decode(sig_b64), msg
        )
        return True
    except InvalidSignature:
        return False

def _lower_headers(h: Mapping[str, str]) -> Dict[str, str]:
    return {str(k).lower(): v for k, v in h.items()}

# Verify client certificate signed by CA
def verify_client_cert(cert_json: str, cert_sig: str) -> Dict[str, Any]:
    """Verify the client (Moodle plugin) certificate using CA public key."""
    ca_pub = Ed25519PublicKey.from_public_bytes(b64u_decode(CA_PUB_B64))
    try:
        ca_pub.verify(b64u_decode(cert_sig), cert_json.encode())
    except InvalidSignature as e:
        raise ValueError("Invalid client certificate signature.") from e
    cert = json.loads(cert_json)
    now = int(time.time())
    if cert.get("exp", 0) < now:
        raise ValueError("Client certificate expired.")
    if cert.get("iss") != "SPE-CA":
        raise ValueError("Unexpected client certificate issuer.")
    if not cert.get("pubkey"):
        raise ValueError("Client certificate missing pubkey.")
    return cert

# Sign server response
def sign_response(path: str, body: str) -> Dict[str, str]:
    """
    Sign server response over the canonical message: f"{path}\\n{body}".
    'path' must be exactly what the client uses when verifying (no trailing slash differences).
    """
    sk = Ed25519PrivateKey.from_private_bytes(b64u_decode(SERVER_PRIV_B64))
    msg = f"{path}\n{body}".encode()
    sig = sk.sign(msg)
    return {
        "X-SPE-Server-Cert": SERVER_CERT_JSON,
        "X-SPE-Server-Sig": b64u_encode(sig),
        "X-SPE-Server-CertSig": SERVER_CERT_SIG,
    }

# Verify client request
def verify_client_request(path: str, body: str, headers: Mapping[str, str]) -> None:
    """
    Verify client request signature and cert chain.
    Accepts any mapping; header names are normalized to lowercase.
    """
    h = _lower_headers(headers)
    cert_json = h.get("x-spe-client-cert")
    cert_sig  = h.get("x-spe-client-certsig")
    req_sig   = h.get("x-spe-client-sig")

    if not cert_json or not cert_sig or not req_sig:
        raise ValueError("Missing client certificate or signature headers.")

    cert = verify_client_cert(cert_json, cert_sig)

    msg = f"{path}\n{body}".encode()
    pubkey_b64 = cert["pubkey"]
    if not verify_signature(pubkey_b64, msg, req_sig):
        raise ValueError("Invalid client request signature.")
