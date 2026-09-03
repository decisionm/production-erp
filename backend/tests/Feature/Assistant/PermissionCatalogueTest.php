<?php

namespace Tests\Feature\Assistant;

use App\Modules\Core\Services\PermissionService;
use Tests\TestCase;

class PermissionCatalogueTest extends TestCase
{
    public function test_ask_erp_is_a_catalogue_module(): void
    {
        $names = app(PermissionService::class)->allPermissionNames();

        $this->assertContains('assistant.view', $names);
        $this->assertContains('assistant.manage', $names);
    }

    public function test_ask_erp_config_has_safe_defaults(): void
    {
        $this->assertSame('claude-opus-5', config('ask-erp.model'));
        $this->assertSame(200, config('ask-erp.row_limit'));
        $this->assertNull(config('ask-erp.connection'));
    }
}
