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
