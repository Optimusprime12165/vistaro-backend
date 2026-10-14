<?php

namespace Tests\Feature\Catalog;

use App\Enums\AdminRole;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogTestHelpers;
use Tests\TestCase;

class AdminImageTest extends TestCase
{
    use CatalogTestHelpers, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vistaro.images.disk' => 'public']);
        Storage::fake('public');
        $this->product = $this->makeProduct();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/admin/products/{$this->product->id}/images{$suffix}";
    }

    private function uploadImages(array $files)
    {
        return $this->actingAsAdmin()->post($this->url(), ['images' => $files], ['Accept' => 'application/json']);
    }

    public function test_images_are_uploaded_ordered_and_the_first_becomes_primary(): void
    {
        $r = $this->uploadImages([$this->upload('front.png'), $this->upload('back.png')])->assertCreated()
            ->assertJsonCount(2, 'data.images')->assertJsonPath('data.images.0.is_primary', true)->assertJsonPath('data.images.1.is_primary', false);

        $this->assertSame([0, 1], collect($r->json('data.images'))->pluck('sort_order')->all());
        $images = $this->product->images()->get();

        foreach ($images as $img) {
            $this->assertSame('public', $img->disk);
            $this->assertMatchesRegularExpression('#^products/' . $this->product->id . '/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#', $img->path, 'server-generated name, extension from content');
            Storage::disk('public')->assertExists($img->path);
        }

        $this->uploadImages([$this->upload('third.png')])->assertCreated();                // later uploads append, primary unchanged
        $all = $this->product->images()->get();
        $this->assertSame([0, 1, 2], $all->pluck('sort_order')->all());
        $this->assertSame(1, $all->where('is_primary', true)->count());
    }

    public function test_the_extension_comes_from_the_file_content_not_the_client_name(): void
    {
        $this->uploadImages([$this->upload('holiday.php.jpg')])->assertCreated();   // really a PNG

        $this->assertStringEndsWith('.png', $this->product->images()->first()->path);
        $this->assertStringNotContainsString('holiday', $this->product->images()->first()->path);
    }

    public function test_invalid_uploads_are_rejected_and_nothing_is_stored(): void
    {
        $cases = [
            'text renamed to png' => $this->upload('x.png', 'just some text, not an image'),
            'php script' => $this->upload('shell.php', '<?php echo 1;'),
            'php renamed to jpg' => $this->upload('shell.jpg', '<?php system($_GET["c"]);'),
            'svg' => $this->upload('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'pdf' => $this->upload('x.pdf', "%PDF-1.4\n%fake"),
            'too large (6 MB)' => $this->upload('big.png', $this->pngBytes(6 * 1024)),
        ];

        foreach ($cases as $label => $file) {
            $this->uploadImages([$file])->assertStatus(422, $label)->assertJsonPath('success', false);
        }

        $this->actingAsAdmin()->postJson($this->url(), [])->assertStatus(422);
        $this->actingAsAdmin()->postJson($this->url(), ['images' => 'not-an-array'])->assertStatus(422);
        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'rejected uploads leave no files behind');
    }

    public function test_one_bad_file_rejects_the_whole_upload(): void
    {
        $this->uploadImages([$this->upload('ok.png'), $this->upload('bad.png', 'nope')])->assertStatus(422);

        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_https_urls_can_be_attached_but_nothing_else(): void
    {
        $this->actingAsAdmin()->postJson($this->url(), ['urls' => ['https://images.example.com/a.jpg']])->assertCreated()
            ->assertJsonPath('data.images.0.url', 'https://images.example.com/a.jpg')->assertJsonPath('data.images.0.is_primary', true);
        $this->assertSame('external', $this->product->images()->first()->disk);

        foreach (['http://insecure.example/a.jpg', 'javascript:alert(1)', 'ftp://x/a.jpg', 'not a url', '//evil.example/a.jpg'] as $bad) {
            $this->actingAsAdmin()->postJson($this->url(), ['urls' => [$bad]])->assertStatus(422);
        }
        $this->assertSame(1, ProductImage::count());
    }

    public function test_a_product_holds_at_most_ten_images(): void
    {
        $urls = array_map(fn ($i) => "https://images.example.com/{$i}.jpg", range(1, 10));
        $this->actingAsAdmin()->postJson($this->url(), ['urls' => $urls])->assertCreated();
        $this->assertSame(10, ProductImage::count());

        $this->uploadImages([$this->upload('eleventh.png')])->assertStatus(422)->assertJsonPath('errors.images.0', 'At most 10 images per product.');
        $this->actingAsAdmin()->postJson($this->url(), ['urls' => ['https://images.example.com/11.jpg']])->assertStatus(422);
        $this->assertSame(10, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'the refused file was not left on disk');

        $this->actingAsAdmin()->postJson($this->url(), ['urls' => array_map(fn ($i) => "https://x.example/{$i}.jpg", range(1, 11))])->assertStatus(422);
    }

    public function test_deleting_an_image_removes_the_file_and_promotes_a_new_primary(): void
    {
        $this->uploadImages([$this->upload('a.png'), $this->upload('b.png')])->assertCreated();
        [$first, $second] = $this->product->images()->get()->all();

        $this->actingAsAdmin()->deleteJson($this->url("/{$first->id}"))->assertOk()
            ->assertJsonCount(1, 'data.images')->assertJsonPath('data.images.0.id', $second->id)->assertJsonPath('data.images.0.is_primary', true);

        Storage::disk('public')->assertMissing($first->path);
        Storage::disk('public')->assertExists($second->path);
        $this->assertDatabaseMissing('product_images', ['id' => $first->id]);

        $this->actingAsAdmin()->deleteJson($this->url("/{$second->id}"))->assertOk()->assertJsonCount(0, 'data.images');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_image_of_another_product_cannot_be_touched(): void
    {
        $other = $this->makeProduct();
        $foreign = ProductImage::factory()->for($other)->create(['is_primary' => true]);

        $this->actingAsAdmin()->deleteJson($this->url("/{$foreign->id}"))->assertNotFound();
        $this->actingAsAdmin()->patchJson($this->url("/{$foreign->id}/primary"))->assertNotFound();
        $this->actingAsAdmin()->deleteJson($this->url('/999999'))->assertNotFound();
        $this->assertDatabaseHas('product_images', ['id' => $foreign->id]);
    }

    public function test_images_can_be_reordered_and_the_primary_changed(): void
    {
        $this->actingAsAdmin()->postJson($this->url(), ['urls' => ['https://x.example/1.jpg', 'https://x.example/2.jpg', 'https://x.example/3.jpg']])->assertCreated();
        [$a, $b, $c] = $this->product->images()->get()->all();

        $r = $this->actingAsAdmin()->patchJson($this->url('/order'), ['order' => [$c->id, $a->id, $b->id]])->assertOk();
        $this->assertSame([$c->id, $a->id, $b->id], collect($r->json('data.images'))->pluck('id')->all());

        $this->actingAsAdmin()->patchJson($this->url("/{$b->id}/primary"))->assertOk();
        $this->assertSame([$b->id], $this->product->images()->where('is_primary', true)->pluck('id')->all(), 'exactly one primary');

        // the storefront reflects both the order and the primary flag
        $public = $this->guest()->getJson("/api/v1/products/{$this->product->slug}")->json('data.images');
        $this->assertSame([$c->id, $a->id, $b->id], collect($public)->pluck('id')->all());
        $this->assertSame([$b->id], collect($public)->where('is_primary', true)->pluck('id')->values()->all());

        $other = ProductImage::factory()->for($this->makeProduct())->create();
        foreach ([[$c->id, $a->id], [$c->id, $a->id, $b->id, $other->id], [$c->id, $a->id, $other->id], [$a->id, $a->id, $b->id], []] as $bad) {
            $this->actingAsAdmin()->patchJson($this->url('/order'), ['order' => $bad])->assertStatus(422);
        }
        $this->assertSame([$c->id, $a->id, $b->id], $this->product->images()->pluck('id')->all(), 'a rejected order changes nothing');
    }

    public function test_only_catalogue_admins_may_manage_images(): void
    {
        $this->actingAsAdmin(AdminRole::Staff)->postJson($this->url(), ['urls' => ['https://x.example/1.jpg']])->assertForbidden();
        $this->actingAsCustomer()->postJson($this->url(), ['urls' => ['https://x.example/1.jpg']])->assertUnauthorized();
        $this->guest()->postJson($this->url(), ['urls' => ['https://x.example/1.jpg']])->assertUnauthorized();
        $this->actingAsAdmin(AdminRole::SuperAdmin)->postJson($this->url(), ['urls' => ['https://x.example/1.jpg']])->assertCreated();
        $this->assertSame(1, ProductImage::count());
    }
}
