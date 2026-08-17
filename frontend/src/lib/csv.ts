/**
 * Save a file the SERVER built — the Download / Export Center's CSVs.
 *
 * There used to be a client-side CSV builder here (toCsv/downloadCsv) behind
 * the report tabs' "Export CSV" buttons. It exported exactly the rows the
 * table happened to be showing — no backend, no gating, no audit row. Phase
 * 4.5 retired it: an export is a server-side read of the same query the
 * screen runs, with the same filters, for the same reader (POST
 * /api/v1/exports/{kind}), and the file arrives as a blob already escaped,
 * BOM'd and named. Nothing here builds CSV any more; this only hands the
 * bytes to the browser under the name the server chose.
 */
export function downloadBlob(filename: string, blob: Blob): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}
