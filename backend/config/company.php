<?php

return [
    /**
     * The company's display name, as outward-facing PAPER prints it — the
     * same name the login screen shows and the carton label carries
     * (DEC-20260807-009); the frontend's own copy is
     * frontend/src/lib/company.ts.
     *
     * NOT `app.name`. That is the application's name and on live it reads
     * "Production ERP", which is what an attendance sheet handed to
     * somebody on the floor would then be headed — the software's name
     * where the factory's belongs. One instance per company
     * (TECHNICAL-DOCS §2), so a constant is the honest source; the env var
     * exists only so another deployment does not have to edit code.
     */
    'name' => env('COMPANY_NAME', 'Swaashpet Polymers Private Limited'),
];
