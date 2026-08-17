import { Modal } from 'antd';
import { apiErrorParts } from './apiError';

/**
 * THE shared "the server refused" modal.
 *
 * It replaces the six hand-rolled copies that flattened
 * `Object.values(errors).flat().join(' ')` and threw the field keys away —
 * `ProductStandardsPage`, `ProductionConfigurationPage`, `ApproveProductionPage`,
 * `ShiftProductionEntryPage`, `PackingMaterialsTab` and
 * `ConfigurationReviewPanel`. A form with thirty inputs answered "This field
 * is required." and left the reader hunting for which one; here each message
 * is printed UNDER THE KEY IT BELONGS TO, verbatim.
 *
 * `fallback` is the caller's own words for a failure the server did not
 * describe — "Refresh and try again." on the cancel paths, the shared
 * "Unexpected error." everywhere else. Each converted handler kept the exact
 * sentence it ended on, so adopting this helper changed nothing except that
 * the reader now learns which field was refused.
 *
 * WHAT IT DOES NOT CLAIM: this is not yet the app's only failure modal. A
 * hundred-odd handlers still print `response.data.message` on its own — they
 * never flattened a key away, so they were not the defect, but they do drop
 * field errors entirely and adopting this helper would improve them. That is
 * a separate sweep, and this docblock will not describe it as done.
 *
 * NOT for a `configuration_in_use` refusal — that one carries a blocking list
 * with counts and an Archive alternative, and belongs in
 * `DeleteConfigurationModal`. Callers discriminate with `configurationInUse()`
 * before falling back to this.
 */
export function showApiError(error: unknown, title = 'Could not save', fallback?: string): void {
    const parts = apiErrorParts(error, fallback);

    Modal.error({
        title,
        content:
            parts.fields.length === 0 ? (
                parts.message
            ) : (
                <div>
                    {parts.fields.map((field) => (
                        <div key={field.field} style={{ marginBottom: 8 }}>
                            {/* The key, named — the whole point of this helper. */}
                            <div style={{ fontWeight: 600 }}>{field.label}</div>
                            {field.messages.map((message, index) => (
                                <div key={index}>{message}</div>
                            ))}
                        </div>
                    ))}
                </div>
            ),
    });
}
