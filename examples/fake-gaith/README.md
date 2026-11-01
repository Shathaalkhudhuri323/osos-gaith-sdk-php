# Fake GAITH

A stand-in for GAITH's public chatbot API, for trying the SDK end to end before GAITH
accepts identity proofs. Not GAITH, and never for production.

It does to each request what GAITH must do once it supports the SDK: checks the API key,
checks the identity proof on every call (`FakeGaithProofVerifier.php`, the same checks
GAITH needs), takes the user from the proof and refuses a request that names someone
else, and answers the on/off check from a customer list. Its replies say back who you
are, so a wrong identity shows at once. Every request is logged with its `X-Trace-ID`.

## Run it

1. A throwaway key pair (never reuse a real one here):

   ```bash
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out proof.pem
   openssl pkey -in proof.pem -pubout -out proof.pub.pem
   ```

2. Start the fake with the public half:

   ```bash
   FAKE_GAITH_PUBLIC_KEY_PATH=proof.pub.pem php -S localhost:8787 examples/fake-gaith/server.php
   ```

   | Setting | Default | |
   |---|---|---|
   | `FAKE_GAITH_PUBLIC_KEY_PATH` | (required) | public key the proofs must verify with |
   | `FAKE_GAITH_CHATBOT_ID` | `11111111-1111-1111-1111-111111111111` | the only chatbot it serves |
   | `FAKE_GAITH_API_KEY` | `fake-gaith-key` | expected `X-API-Key` |
   | `FAKE_GAITH_ISSUER` | `qhse` | expected product (`iss`) |
   | `FAKE_GAITH_ENABLED_CUSTOMERS` | (empty: all on) | comma-separated customers that are switched on |

3. Check it from the command line, signing as the host would:

   ```bash
   GAITH_PROOF_PRIVATE_KEY_PATH=proof.pem php examples/fake-gaith/try.php
   ```

   `TRY_PRODUCT`, `TRY_CUSTOMER` and `TRY_PERSON` (default `qhse`, `7`, `42`) choose who to be.

## Point a host app at it

In the host's `.env` (QHSE or the ERP, with the SDK installed):

```dotenv
GAITH_CHATBOT_BASE_URL=http://localhost:8787
GAITH_CHATBOT_ID=11111111-1111-1111-1111-111111111111
GAITH_CHATBOT_API_KEY=fake-gaith-key
GAITH_PROOF_KEY_ID=local-1
GAITH_PROOF_PRIVATE_KEY_PATH=/path/to/proof.pem
```

Then sign in to the host and open the chat box: the reply names the signed-in user's
customer and person, and the fake's console shows the same trace id as the host's log.

It serves the chat stream, the on/off check, uploads and an always-empty history; anything
else answers 404.
