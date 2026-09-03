/**
 * THE PULL MUST NEVER FAIL SILENTLY.
 *
 * Live state on 03-Sep-2026: the agent was healthy — checking in every minute
 * and completing `masters.received` reads out of Tally — the operator had
 * pressed "Pull Outstandings from Tally", and the ERP had NOTHING. No
 * receivable row, no `receivables.received` event, no counts. From the cloud
 * side, "nobody pressed it" and "Tally refused the request" were the same
 * observation, which is #64's lesson in a different costume.
 *
 * The mechanism: a throw from either Tally read propagated out of
 * exportOutstandingPosition, so runReceivablesSync never reached its post.
 *
 * These tests run against a REAL local HTTP server rather than a mock, because
 * what is under test is the behaviour of the actual axios call when Tally
 * answers badly — a mock of our own axios usage would be testing the mock.
 *
 * EVERY VALUE IS SYNTHETIC (FC-06).
 */

const test = require('node:test');
const assert = require('node:assert');
const http = require('node:http');

const { exportOutstandingPosition } = require('../dist/tally/receivables.js');

/** A stand-in Tally gateway; `handler` decides what each request gets. */
function startTally(handler) {
    const server = http.createServer(handler);

    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => resolve(server));
    });
}

function close(server) {
    return new Promise((resolve) => server.close(resolve));
}

const BILLS_XML = `<ENVELOPE><BODY><EXPORTDATA><REQUESTDATA><COLLECTION>
    <BILLS>
        <NAME>INV-201</NAME>
        <PARENT>Northwind Traders</PARENT>
        <BILLDATE>20260801</BILLDATE>
        <BILLCREDITPERIOD>30 Days</BILLCREDITPERIOD>
        <CLOSINGBALANCE>-10000.00</CLOSINGBALANCE>
    </BILLS>
</COLLECTION></REQUESTDATA></EXPORTDATA></BODY></ENVELOPE>`;

test('a failing Sales Order read does not throw away the bills that DID answer', async () => {
    let seen = 0;

    const server = await startTally((req, res) => {
        seen += 1;

        // First request is the bills collection; the orders read then fails
        // the way a Tally that dislikes the request actually fails.
        if (seen === 1) {
            res.writeHead(200, { 'Content-Type': 'text/xml' });
            res.end(BILLS_XML);

            return;
        }

        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('Could not set SVCurrentCompany');
    });

    try {
        const { port } = server.address();

        const position = await exportOutstandingPosition(
            { host: '127.0.0.1', port, company: 'Synthetic Test Co' },
            '2026-09-03',
        );

        // THE WHOLE POINT: it resolves. Before this, the throw reached
        // runReceivablesSync and the agent posted nothing at all.
        assert.strictEqual(position.bills.length, 1);
        assert.strictEqual(position.bills[0].due_date, '2026-08-31');

        // The half that failed is empty, not invented.
        assert.deepStrictEqual(position.orders, []);
    } finally {
        await close(server);
    }
});

test('a Tally that refuses every request still returns a position instead of throwing', async () => {
    const server = await startTally((_req, res) => {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('No such report');
    });

    try {
        const { port } = server.address();

        // An empty position posts as empty, and the cloud declines to wipe a
        // standing position on an entirely empty pull — so the press still
        // leaves a `receivables.received` event and a reason in the log.
        const position = await exportOutstandingPosition(
            { host: '127.0.0.1', port, company: 'Synthetic Test Co' },
            '2026-09-03',
        );

        assert.deepStrictEqual(position.bills, []);
        assert.deepStrictEqual(position.orders, []);
    } finally {
        await close(server);
    }
});

test('a refused bills COLLECTION falls through to the report request', async () => {
    const asked = [];

    const server = await startTally((req, res) => {
        let body = '';

        req.on('data', (chunk) => {
            body += chunk;
        });

        req.on('end', () => {
            asked.push(body.includes('<TYPE>Collection</TYPE>') ? 'collection' : 'report');

            // The collection is refused; the report answers.
            if (body.includes('<TYPE>Collection</TYPE>')) {
                res.writeHead(500, { 'Content-Type': 'text/plain' });
                res.end('Unknown collection type');

                return;
            }

            res.writeHead(200, { 'Content-Type': 'text/xml' });
            res.end(BILLS_XML);
        });
    });

    try {
        const { port } = server.address();

        const position = await exportOutstandingPosition(
            { host: '127.0.0.1', port, company: 'Synthetic Test Co' },
            '2026-09-03',
        );

        // The fallback is the entire insurance policy against the Collection
        // shape being wrong, and it has to survive an ERROR, not just an
        // empty answer.
        assert.strictEqual(position.bills.length, 1);
        assert.strictEqual(asked[0], 'collection');
        assert.strictEqual(asked[1], 'report');
    } finally {
        await close(server);
    }
});
