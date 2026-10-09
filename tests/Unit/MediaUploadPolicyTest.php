<?php

namespace Tests\Unit;

use App\Arkon\Media\UploadPolicy;
use Tests\TestCase;

class MediaUploadPolicyTest extends TestCase
{
    public function test_runtime_effective_limits_reserve_multipart_headroom(): void
    {
        $this->assertSame(5242880, UploadPolicy::rules()['maxImageUploadBytes']);
        $this->assertSame(2097152, UploadPolicy::forRuntime('2M', '8M')['effectiveMaxBytes']);
        $this->assertTrue(UploadPolicy::forRuntime('2M', '8M')['runtimeLimited']);
        $this->assertSame(4194304, UploadPolicy::forRuntime('8M', '5M')['effectiveMaxBytes']);
        $this->assertSame(5242880, UploadPolicy::forRuntime('8M', '10M')['effectiveMaxBytes']);
        $this->assertSame(5242880, UploadPolicy::forRuntime('0', '0')['effectiveMaxBytes']);
        $this->assertSame('5 MiB', UploadPolicy::formatBytes(5242880));
    }
}
