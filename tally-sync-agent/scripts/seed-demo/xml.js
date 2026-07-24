const axios = require('axios');

const TALLY_URL = process.env.TALLY_URL || 'http://127.0.0.1:9000';
const COMPANY_NAME = process.env.TALLY_COMPANY || 'Amruthaa & Co';

function escapeXml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

/** ISO (2026-07-22) or already-compact (20260722) -> Tally's YYYYMMDD. */
function toTallyDate(isoDate) {
    return isoDate.replace(/-/g, '').slice(0, 8);
}

function envelope(messageXml, reportName = 'All Masters') {
    return `<ENVELOPE>
 <HEADER>
  <TALLYREQUEST>Import Data</TALLYREQUEST>
 </HEADER>
 <BODY>
  <IMPORTDATA>
   <REQUESTDESC>
    <REPORTNAME>${reportName}</REPORTNAME>
    <STATICVARIABLES>
     <SVCURRENTCOMPANY>${escapeXml(COMPANY_NAME)}</SVCURRENTCOMPANY>
    </STATICVARIABLES>
   </REQUESTDESC>
   <REQUESTDATA>
    <TALLYMESSAGE xmlns:UDF="TallyUDF">
${messageXml}
    </TALLYMESSAGE>
   </REQUESTDATA>
  </IMPORTDATA>
 </BODY>
</ENVELOPE>`;
}

/**
 * Posts one <TALLYMESSAGE> envelope (one or more master/voucher blocks) and
 * reports back Tally's CREATED/ALTERED/ERRORS counts. Tally returns HTTP 200
 * even on failure — the real result is always in the response body.
 */
async function postImport(label, messageXml, reportName = 'All Masters') {
    const xml = envelope(messageXml, reportName);
    try {
        const { data } = await axios.post(TALLY_URL, xml, {
            headers: { 'Content-Type': 'text/xml' },
            timeout: 30000,
            responseType: 'text',
        });
        const created = (data.match(/<CREATED>(\d+)<\/CREATED>/) || [])[1] || '0';
        const altered = (data.match(/<ALTERED>(\d+)<\/ALTERED>/) || [])[1] || '0';
        const errors = (data.match(/<ERRORS>(\d+)<\/ERRORS>/) || [])[1] || '0';
        const lineError = (data.match(/<LINEERROR>(.*?)<\/LINEERROR>/s) || [])[1];
        const exceptions = (data.match(/<EXCEPTIONS>(\d+)<\/EXCEPTIONS>/) || [])[1] || '0';

        const ok = errors === '0' && !lineError;
        const status = ok ? 'OK' : 'FAIL';
        console.log(
            `[${status}] ${label} — created=${created} altered=${altered} errors=${errors} exceptions=${exceptions}${lineError ? ` — ${lineError}` : ''}`,
        );
        if (!ok) {
            console.log(`         raw response: ${data.replace(/\s+/g, ' ').slice(0, 500)}`);
        }
        return ok;
    } catch (err) {
        console.log(`[FAIL] ${label} — request error: ${err.message}`);
        return false;
    }
}

module.exports = { TALLY_URL, COMPANY_NAME, escapeXml, toTallyDate, envelope, postImport };
