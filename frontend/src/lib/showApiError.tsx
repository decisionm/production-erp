import { Modal } from 'antd';
import { apiErrorParts } from './apiError';

/**
 * THE shared "the server refused" modal.
 *
 * It replaces two hand-rolled copies (`ProductStandardsPage.tsx` and
 * `ProductionConfigurationPage.tsx`) that flattened `Object.values(errors)`
 * and threw the field keys away — so a form with thirty inputs answered
 * "This field is required." and left the reader hunting for which one.
 * Here each message is printed UNDER THE KEY IT BELONGS TO, verbatim.
 *
 * The fallback wording is unchanged from those two handlers ("Unexpected
 * error."), so adopting this helper changes nothing except that the reader
 * now learns which field was refused.
 *
 * NOT for a `configuration_in_use` refusal — that one carries a blocking list
 * with counts and an Archive alternative, and belongs in
 * `DeleteConfigurationModal`. Callers discriminate with `configurationInUse()`
 * before falling back to this.
 */
export function showApiError(error: unknown, title = 'Could not save'): void {
    const parts = apiErrorParts(error);

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
