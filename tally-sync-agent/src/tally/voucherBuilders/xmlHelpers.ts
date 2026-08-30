export function escapeXml(value: string | number): string {
    return (
        String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;')
            // Non-ASCII → numeric character references. Verified against the
            // client's TallyPrime (SPE-3 test): raw UTF-8 bytes in a narration
            // render as mojibake ("—" became "â") because Tally doesn't treat
            // the request body as UTF-8. Entities sidestep charset guessing.
            .replace(/[\u0080-\uffff]/g, (ch) => `&#${ch.charCodeAt(0)};`)
    );
}

/** Tally wants voucher dates as YYYYMMDD, no separators. */
export function toTallyDate(isoDate: string): string {
    return isoDate.replace(/-/g, '').slice(0, 8);
}

export function envelope(companyName: string, tallyMessageXml: string): string {
    return `<ENVELOPE>
  <HEADER>
    <TALLYREQUEST>Import Data</TALLYREQUEST>
  </HEADER>
  <BODY>
    <IMPORTDATA>
      <REQUESTDESC>
        <REPORTNAME>Vouchers</REPORTNAME>
        <STATICVARIABLES>
          <SVCURRENTCOMPANY>${escapeXml(companyName)}</SVCURRENTCOMPANY>
        </STATICVARIABLES>
      </REQUESTDESC>
      <REQUESTDATA>
        <TALLYMESSAGE xmlns:UDF="TallyUDF">
${tallyMessageXml}
        </TALLYMESSAGE>
      </REQUESTDATA>
    </IMPORTDATA>
  </BODY>
</ENVELOPE>`;
}

/**
 * The last lock before a voucher of any kind is built: this agent's
 * `tallyCompanyName` (its own local settings — the company its local Tally
 * is actually open on) must equal, BYTE-FOR-BYTE, the `allowed_company` the
 * cloud staged on the voucher.
 *
 * The cloud trims SURROUNDING WHITESPACE from its allowed-company config
 * exactly once, before treating it as configured/blank and before writing
 * it to the payload (config-authoring convenience, not a relaxed match) —
 * this function does not repeat that trim, and does no case-folding either:
 * what arrives here is compared verbatim against `companyName`. Once the
 * cloud's one normalization has run, nothing softens the comparison
 * further.
 *
 * Blank/missing `allowed_company` and any mismatch are BOTH permanent
 * failures for the voucher: the sync loop reports them as a rejection
 * (never "unverified"), so nothing is retried automatically — a person has
 * to fix the cloud config, the agent config, or both.
 *
 * Grew out of the Purchase Order gate (purchaseOrder.ts, which delegates
 * here) when the 28-Aug rehearsal proved the same failure on a Receipt
 * Note: a voucher reached an obsolete Tally company nothing had checked.
 * `voucherKind` only words the error — the check is identical for every
 * voucher type that carries an allowed_company.
 */
export function requireAllowedCompanyFor(
    voucherKind: string,
    allowedCompany: string | null | undefined,
    companyName: string,
): void {
    if (typeof allowedCompany !== 'string' || allowedCompany.trim() === '') {
        throw new Error(
            `${voucherKind} payload has no allowed_company (the one Tally company it may post to) — refusing to build: `
            + `this agent never posts a ${voucherKind} without the cloud naming that company`,
        );
    }
    if (allowedCompany !== companyName) {
        throw new Error(
            `${voucherKind} payload's allowed_company ("${allowedCompany}") does not match this agent's configured `
            + `Tally company ("${companyName}") — refusing to build: never posting a ${voucherKind} to the wrong Tally company`,
        );
    }
}
