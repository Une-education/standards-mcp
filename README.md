# Standards MCP — Global Technical Compliance & Dual-Use Export Gateway

`standards.une.education` is a deterministic, cryptographically verifiable Model Context Protocol (MCP) server designed to evaluate industrial standards, product certification roadmaps, and strategic export restrictions across major global markets.

Built as a core pillar of the AtoA (Agent-to-Agent) compliance architecture, it bridges technical engineering baselines with cross-border trade friction.

### Strategic Scope & Roadmap

- **Active Pillar — Sino-Industrial Compliance Engine:**
  - **National Standards Lineage:** Directional mapping of mandatory (`GB`) and recommended (`GB/T`) standards, tracking superseded versions and transition deadlines.
  - **China Compulsory Certification (CCC):** Mandatory factory auditing and catalog scopes under SAMR / CNCA authority.
  - **Strategic Export Controls:** Deterministic screening against MOFCOM dual-use licensing lists, factoring in bilateral trade suspensions (e.g. Notices 58, 70, and bilateral truce clauses for battery graphite, rare earths, and clean-tech materials).

- **Upcoming Multilateral Modules:**
  - Cross-standard mapping (`GB` <-> `ISO` / `IEC` <-> `EN/CE`).
  - EU conformity assessments (Machinery Directive, RoHS/REACH, Cyber Resilience Act).
  - US dual-use and conformity frameworks (BIS / EAR / ITAR screening and UL/ANSI registries).

### Endpoint & Protocol

- **Protocol:** JSON-RPC 2.0 / MCP (2024-11-05)
- **Production Endpoint:** `https://standards.une.education/mcp.php`
- **Verification:** Sealed deterministic state proof (`result_hash`, `attestation_id`, HMAC signature).
