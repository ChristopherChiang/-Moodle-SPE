import base64, json, time
from textwrap import dedent
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

# Functions
b64u = lambda b: base64.urlsafe_b64encode(b).rstrip(b"=").decode()
def minjson(o): return json.dumps(o, separators=(",", ":")).encode()

def keypair():
    sk = Ed25519PrivateKey.generate()
    raw = sk.private_bytes(
        encoding=serialization.Encoding.Raw,
        format=serialization.PrivateFormat.Raw,
        encryption_algorithm=serialization.NoEncryption(),
    )
    pk = sk.public_key().public_bytes(
        encoding=serialization.Encoding.Raw,
        format=serialization.PublicFormat.Raw,
    )
    return sk, raw, pk

def sign_with_ca(ca_sk: Ed25519PrivateKey, cert_obj: dict):
    data = minjson(cert_obj)
    sig  = ca_sk.sign(data)
    return data.decode(), b64u(sig)

def emit_php_defines(
    ca_pub_b64: str,
    client_priv_b64: str,
    client_cert: dict,
    client_cert_sig_b64: str,
    server_cert: dict,
    server_cert_sig_b64: str,
) -> None:
    client_cert_json = json.dumps(client_cert, separators=(',', ':'))
    server_cert_json = json.dumps(server_cert, separators=(',', ':'))

    php = f"""\
    define('SPE_CA_PUB_B64',         '{ca_pub_b64}');
    define('SPE_CLIENT_PRIV_B64',    '{client_priv_b64}');
    define('SPE_CLIENT_CERT_JSON',   '{client_cert_json}');
    define('SPE_CLIENT_CERT_SIG',    '{client_cert_sig_b64}');
    define('SPE_SERVER_CERT_JSON',   '{server_cert_json}');
    define('SPE_SERVER_CERT_SIG',    '{server_cert_sig_b64}');
    """
    print(dedent(php))

# ---------------- Generate keys ----------------------

# Certificate Authority
ca_sk, ca_seed, ca_pk = keypair()
ca_pub_b64 = b64u(ca_pk)

# Expiry time
exp = int(time.time()) + 365 * 24 * 3600

# API
srv_sk, srv_seed, srv_pk = keypair()
srv_cert = {"id": "spe-api", "pubkey": b64u(srv_pk), "exp": exp, "iss": "SPE-CA"}
srv_cert_json, srv_cert_sig = sign_with_ca(ca_sk, srv_cert)

# SPE
cli_sk, cli_seed, cli_pk = keypair()
cli_cert = {"id": "spe-plugin", "pubkey": b64u(cli_pk), "exp": exp, "iss": "SPE-CA"}
cli_cert_json, cli_cert_sig = sign_with_ca(ca_sk, cli_cert)

# Print results
print("=================== Certificate Authority ===================")
print("CA_PUB_B64      =", ca_pub_b64)

print("\n\n====================== API ======================")
print(f'CA_PUB_B64       = "{ca_pub_b64}"')
print(f'SERVER_PRIV_B64  = "{b64u(srv_seed)}"')
print(f'SERVER_CERT_JSON = \'{srv_cert_json}\'')
print(f'SERVER_CERT_SIG  = "{srv_cert_sig}"')

print("\n\n====================== SPE ======================")
emit_php_defines(
    ca_pub_b64=ca_pub_b64,
    client_priv_b64=b64u(cli_seed),
    client_cert=cli_cert,
    client_cert_sig_b64=cli_cert_sig,
    server_cert=srv_cert,
    server_cert_sig_b64=srv_cert_sig,
)
