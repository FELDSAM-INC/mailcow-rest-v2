# Problem types

Errors of mailcow REST API v2 are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details
(`application/problem+json`). Refusals that clients act on carry a stable `type` URI that points to a
section of this document. Compare `type` as an exact string. `title` and `detail` are for humans and
may be reworded. Problems not listed here use `type: about:blank`.

Each problem type gets its own `## <name>` section with its status code, its meaning, and any
extension members.
