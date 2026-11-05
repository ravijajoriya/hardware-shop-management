<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * @param  list<UploadedFile>  $images
     */
    private function upload(Product $product, array $images): TestResponse
    {
        return $this->post(
            "/api/admin/products/{$product->id}/images",
            ['images' => $images],
            ['Accept' => 'application/json'],
        );
    }

    /**
     * @return list<string>
     */
    private function storedPaths(Product $product): array
    {
        return $product->images()->pluck('path')->all();
    }

    public function test_guests_cannot_manage_images(): void
    {
        $this->app['auth']->forgetGuards();
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create();

        $this->postJson("/api/admin/products/{$product->id}/images")->assertUnauthorized();
        $this->putJson("/api/admin/products/{$product->id}/images/order", ['ids' => [$image->id]])->assertUnauthorized();
        $this->deleteJson("/api/admin/products/{$product->id}/images/{$image->id}")->assertUnauthorized();
    }

    public function test_admin_can_upload_several_images_at_once(): void
    {
        $product = Product::factory()->create();

        $response = $this->upload($product, [
            UploadedFile::fake()->image('front.jpg', 600, 600),
            UploadedFile::fake()->image('side.png', 600, 600),
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $paths = $this->storedPaths($product);
        $this->assertCount(2, $paths);

        foreach ($paths as $index => $path) {
            Storage::disk('public')->assertExists($path);
            $response->assertJsonPath("data.{$index}.url", Storage::disk('public')->url($path));
        }
    }

    public function test_new_images_go_after_the_existing_ones_and_the_first_is_the_main_image(): void
    {
        $product = Product::factory()->has(ProductVariant::factory(), 'variants')->create();

        $first = $this->upload($product, [UploadedFile::fake()->image('front.jpg')])->json('data.0.id');
        $all = $this->upload($product, [UploadedFile::fake()->image('back.jpg')])->json('data');

        $this->assertSame($first, $all[0]['id']);
        $this->assertCount(2, $all);

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.image_url', $all[0]['url'])
            ->assertJsonPath('data.images.0.id', $all[0]['id'])
            ->assertJsonPath('data.images.1.id', $all[1]['id']);

        // The list only carries the main image.
        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', $all[0]['url'])
            ->assertJsonMissingPath('data.0.images');
    }

    public function test_a_product_can_have_at_most_ten_images(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->for($product)->count(9)->create();

        $this->upload($product, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images' => 'A product can have up to 10 images, and this one already has 9.']);

        $this->upload($product, [UploadedFile::fake()->image('a.jpg')])->assertCreated();

        $this->assertSame(10, $product->images()->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
    }

    public function test_uploads_are_validated(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/admin/products/{$product->id}/images", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images' => 'Choose at least one image.']);

        $this->upload($product, [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images.0']);

        $this->upload($product, [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->image('huge.jpg')->size(3000)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images.1' => 'Each image may not be larger than 2 MB.']);

        $tooMany = array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 11));
        $this->upload($product, $tooMany)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images' => 'A product can have up to 10 images.']);

        $this->assertSame(0, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_big_photos_are_made_smaller_and_saved_as_webp(): void
    {
        $product = Product::factory()->create();
        $photo = UploadedFile::fake()->image('shelf.jpg', 3200, 2400);

        $this->upload($product, [$photo])->assertCreated();

        [$path] = $this->storedPaths($product);
        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame([1600, 1200, IMAGETYPE_WEBP], $this->sizeOf($path));
    }

    public function test_small_webp_photos_shrunk_by_the_browser_are_kept_as_they_are(): void
    {
        $product = Product::factory()->create();
        $photo = UploadedFile::fake()->image('front.webp', 1600, 900);
        $original = file_get_contents($photo->getRealPath());

        $this->upload($product, [$photo])->assertCreated();

        [$path] = $this->storedPaths($product);
        $this->assertSame($original, Storage::disk('public')->get($path));
    }

    public function test_see_through_backgrounds_stay_see_through(): void
    {
        $product = Product::factory()->create();
        $image = imagecreatetruecolor(2000, 1000);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 800, 300, 1200, 700, imagecolorallocate($image, 200, 40, 40));

        $this->upload($product, [$this->photoFile($image, 'png')])->assertCreated();

        [$path] = $this->storedPaths($product);
        $this->assertSame([1600, 800, IMAGETYPE_WEBP], $this->sizeOf($path));

        $stored = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame(127, imagecolorsforindex($stored, imagecolorat($stored, 5, 5))['alpha']);
        $this->assertSame(0, imagecolorsforindex($stored, imagecolorat($stored, 800, 400))['alpha']);
    }

    public function test_sideways_phone_photos_are_turned_upright_without_their_camera_details(): void
    {
        $product = Product::factory()->create();

        // Saved landscape, with a note from the camera that it should be shown turned a quarter
        // clockwise (Exif orientation 6), as phones do.
        $jpeg = $this->photoFile(imagecreatetruecolor(300, 200), 'jpg');
        $tiff = 'MM'.pack('nNn', 42, 8, 1).pack('nnNn', 0x0112, 3, 1, 6)."\0\0".pack('N', 0);
        $exif = "Exif\0\0".$tiff;
        $bytes = file_get_contents($jpeg->getRealPath());
        file_put_contents($jpeg->getRealPath(), substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));
        $this->assertSame(6, exif_read_data($jpeg->getRealPath())['Orientation']);

        $this->upload($product, [$jpeg])->assertCreated();

        [$path] = $this->storedPaths($product);
        $this->assertSame([200, 300, IMAGETYPE_WEBP], $this->sizeOf($path));
        $this->assertStringNotContainsString('Exif', Storage::disk('public')->get($path));
    }

    public function test_photos_too_large_to_open_safely_are_refused(): void
    {
        $product = Product::factory()->create();

        $this->upload($product, [UploadedFile::fake()->image('panorama.jpg', 6001, 400)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images.0' => 'Each image can be up to 6,000 pixels wide and high.']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * @return array{int, int, int} Width, height and image type of a stored photo.
     */
    private function sizeOf(string $path): array
    {
        return array_slice(getimagesizefromstring(Storage::disk('public')->get($path)), 0, 3);
    }

    private function photoFile(\GdImage $image, string $extension): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.Str::random(12).'.'.$extension;
        $extension === 'png' ? imagepng($image, $path) : imagejpeg($image, $path);

        return new UploadedFile($path, "photo.{$extension}", null, null, true);
    }

    public function test_admin_can_reorder_images_to_pick_the_main_one(): void
    {
        $product = Product::factory()->create();
        $ids = collect($this->upload($product, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ])->json('data'))->pluck('id')->all();

        $newOrder = [$ids[2], $ids[0], $ids[1]];

        $this->putJson("/api/admin/products/{$product->id}/images/order", ['ids' => $newOrder])
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.1.id', $ids[0])
            ->assertJsonPath('data.2.id', $ids[1]);

        $this->assertSame($newOrder, $product->images()->pluck('id')->all());
        $this->assertSame($ids[2], $product->fresh()->mainImage->id);
    }

    public function test_reordering_needs_every_image_of_the_product_once(): void
    {
        $product = Product::factory()->create();
        [$a, $b] = ProductImage::factory()->for($product)->count(2)->create()->pluck('id')->all();
        $other = ProductImage::factory()->create()->id;
        $url = "/api/admin/products/{$product->id}/images/order";

        $this->putJson($url, ['ids' => [$a]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids' => 'List every image of this product exactly once.']);

        $this->putJson($url, ['ids' => [$a, $other]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);

        $this->putJson($url, ['ids' => [$a, $a]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids.0']);

        $this->putJson($url, ['ids' => [$b, $a]])->assertOk();
    }

    public function test_admin_can_delete_one_image(): void
    {
        $product = Product::factory()->create();
        $images = $this->upload($product, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ])->json('data');
        [$deletedPath, $keptPath] = $this->storedPaths($product);

        $this->deleteJson("/api/admin/products/{$product->id}/images/{$images[0]['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $images[1]['id']);

        Storage::disk('public')->assertMissing($deletedPath);
        Storage::disk('public')->assertExists($keptPath);
    }

    public function test_an_image_can_only_be_deleted_through_its_own_product(): void
    {
        $product = Product::factory()->create();
        $otherImage = ProductImage::factory()->create();

        $this->deleteJson("/api/admin/products/{$product->id}/images/{$otherImage->id}")->assertNotFound();

        $this->assertModelExists($otherImage);
    }

    public function test_deleting_a_product_deletes_all_its_images(): void
    {
        $product = Product::factory()->create();
        $this->upload($product, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);
        $paths = $this->storedPaths($product);

        $this->deleteJson("/api/admin/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseCount('product_images', 0);

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }
}
