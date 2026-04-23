<?php

namespace Massif\ResponsiveImages\Tests\Feature;

use Massif\ResponsiveImages\ServiceProvider;
use Massif\ResponsiveImages\Tags\ResponsiveImage;
use Massif\ResponsiveImages\Tests\TestCase;
use ReflectionProperty;

class TagAliasRegistrationTest extends TestCase
{
    public function test_pic_is_no_longer_in_the_auto_scanned_tags_folder(): void
    {
        // Structural proof: Statamic's AddonServiceProvider::bootTags() scans
        // src/Tags/. Pic must live outside that folder so it's registered
        // exclusively via ServiceProvider::__construct's conditional logic.
        $this->assertFileDoesNotExist(__DIR__.'/../../src/Tags/Pic.php');
        $this->assertFileExists(__DIR__.'/../../src/Aliases/Pic.php');
    }

    public function test_tag_alias_null_does_not_register_pic(): void
    {
        config(['responsive-images.tag_alias' => null]);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertContains(ResponsiveImage::class, $tags);
        $this->assertNotContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
    }

    public function test_tag_alias_empty_string_does_not_register_pic(): void
    {
        config(['responsive-images.tag_alias' => '']);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertNotContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
    }

    public function test_tag_alias_default_pic_registers_pic(): void
    {
        config(['responsive-images.tag_alias' => 'pic']);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertContains(ResponsiveImage::class, $tags);
        $this->assertContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
        $this->assertSame('pic', \Massif\ResponsiveImages\Aliases\Pic::$handle);
    }

    public function test_tag_alias_custom_photo_sets_handle(): void
    {
        config(['responsive-images.tag_alias' => 'photo']);

        $provider = new ServiceProvider($this->app);
        $tags = $this->readTagsProperty($provider);

        $this->assertContains(\Massif\ResponsiveImages\Aliases\Pic::class, $tags);
        $this->assertSame('photo', \Massif\ResponsiveImages\Aliases\Pic::$handle);
    }

    protected function tearDown(): void
    {
        \Massif\ResponsiveImages\Aliases\Pic::$handle = 'pic';
        parent::tearDown();
    }

    /**
     * AddonServiceProvider's $tags property is protected; read via reflection.
     *
     * @return array<int, class-string>
     */
    private function readTagsProperty(ServiceProvider $provider): array
    {
        $prop = new ReflectionProperty($provider, 'tags');
        $prop->setAccessible(true);
        return (array) $prop->getValue($provider);
    }
}
