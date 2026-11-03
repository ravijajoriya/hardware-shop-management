<?php

namespace Tests\Feature\Admin;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Unit;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Ravi',
            'email' => 'ravi@example.com',
            'password' => 'secret-pass',
        ]);
        Sanctum::actingAs($this->admin);
    }

    private function last(): ActivityLog
    {
        return ActivityLog::latest('id')->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return ActivityLog::orderBy('id')->get()->map(fn (ActivityLog $entry) => $entry->action->value)->all();
    }

    public function test_guests_and_non_admins_cannot_read_the_log(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/activity-logs')->assertUnauthorized();
        $this->getJson('/api/admin/activity-logs/options')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/activity-logs')->assertForbidden();
    }

    public function test_logins_logouts_and_failed_logins_are_logged_with_ip_and_device(): void
    {
        $this->app['auth']->forgetGuards();
        $device = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $this->withHeader('User-Agent', $device);

        $this->postJson('/api/admin/login', ['email' => 'ravi@example.com', 'password' => 'guess'])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => 'nobody@example.com', 'password' => 'guess'])->assertUnprocessable();
        $token = $this->postJson('/api/admin/login', ['email' => 'ravi@example.com', 'password' => 'secret-pass'])->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/admin/logout')->assertNoContent();

        $this->assertSame(['auth.login_failed', 'auth.login_failed', 'auth.logged_in', 'auth.logged_out'], $this->actions());
        [$wrongPassword, $unknownEmail, $in, $out] = ActivityLog::orderBy('id')->get();

        $this->assertNull($wrongPassword->user_id);
        $this->assertSame('Failed login for ravi@example.com', $wrongPassword->description);
        $this->assertSame([
            ['label' => 'Email tried', 'value' => 'ravi@example.com'],
            ['label' => 'Why it failed', 'value' => 'Wrong password'],
        ], $wrongPassword->details);
        $this->assertSame('No account has this email', $unknownEmail->details[1]['value']);
        $this->assertSame('127.0.0.1', $wrongPassword->ip_address);
        $this->assertSame($device, $wrongPassword->user_agent);

        $this->assertSame([$this->admin->id, 'Ravi', 'Logged in'], [$in->user_id, $in->user_name, $in->description]);
        $this->assertSame([$this->admin->id, 'Logged out'], [$out->user_id, $out->description]);
    }

    public function test_category_changes_are_logged_with_old_and_new_values(): void
    {
        $tools = Category::factory()->create(['name' => 'Tools']);

        $id = $this->postJson('/api/admin/categories', ['name' => 'Power Tools'])->assertCreated()->json('data.id');
        $created = $this->last();
        $this->assertSame('Added category Power Tools', $created->description);
        $this->assertSame(['category', $id, 'Power Tools'], [$created->subject_type, $created->subject_id, $created->subject_label]);
        $this->assertContains(['field' => 'Name', 'old' => null, 'new' => 'Power Tools'], $created->changes);
        $this->assertSame('Ravi', $created->user_name);

        $change = ['name' => 'Power Tools', 'parent_id' => $tools->id, 'is_active' => false];
        $this->putJson("/api/admin/categories/{$id}", $change)->assertOk();
        $this->assertSame('Changed category Power Tools: Parent category — → Tools, Active Yes → No', $this->last()->description);
        $this->assertSame([
            ['field' => 'Parent category', 'old' => null, 'new' => 'Tools'],
            ['field' => 'Active', 'old' => 'Yes', 'new' => 'No'],
        ], $this->last()->changes);

        // Saving again without a change, or a delete the shop refuses, adds nothing.
        $count = ActivityLog::count();
        $this->putJson("/api/admin/categories/{$id}", $change)->assertOk();
        $this->deleteJson("/api/admin/categories/{$tools->id}")->assertUnprocessable();
        $this->assertSame($count, ActivityLog::count());

        $this->deleteJson("/api/admin/categories/{$id}")->assertNoContent();
        $this->assertSame('Deleted category Power Tools', $this->last()->description);
        $this->assertContains(['field' => 'Name', 'old' => 'Power Tools', 'new' => null], $this->last()->changes);

        // The delete entry says there is nothing left to open; earlier entries are not rewritten.
        $this->getJson('/api/admin/activity-logs')
            ->assertJsonPath('data.0.action', ['value' => 'category.deleted', 'label' => 'Category deleted'])
            ->assertJsonPath('data.0.section', ['value' => 'categories', 'label' => 'Categories'])
            ->assertJsonPath('data.0.subject', ['type' => 'category', 'id' => $id, 'label' => 'Power Tools', 'exists' => false])
            ->assertJsonPath('data.0.user', ['id' => $this->admin->id, 'name' => 'Ravi'])
            ->assertJsonPath('data.2.subject.exists', true);
    }

    public function test_product_changes_list_each_field_and_a_brand_added_from_the_form_is_logged(): void
    {
        $unit = Unit::firstOrCreate(['short_name' => 'pc'], ['name' => 'Piece', 'allow_decimal' => false]);
        $payload = [
            'category_id' => Category::factory()->create(['name' => 'Paint'])->id,
            'brand_id' => null,
            'new_brand_name' => 'Asian Paints',
            'unit_id' => $unit->id,
            'name' => 'Wall Paint',
            'location_area' => 'shop',
            'is_active' => true,
            'variants' => [['name' => '1 L', 'purchase_price' => 300, 'selling_price' => 360, 'stock_quantity' => 10]],
        ];

        $product = $this->postJson('/api/admin/products', $payload)->assertCreated()->json('data');
        $this->assertSame(['brand.created', 'product.created'], $this->actions());
        $this->assertSame('Added product Wall Paint', $this->last()->description);
        $this->assertContains(['field' => 'Brand', 'old' => null, 'new' => 'Asian Paints'], $this->last()->changes);
        $this->assertContains(['field' => 'Selling price (1 L)', 'old' => null, 'new' => '₹360.00'], $this->last()->changes);
        $this->assertContains(['field' => 'Stock (1 L)', 'old' => null, 'new' => '10 pc'], $this->last()->changes);

        $this->putJson("/api/admin/products/{$product['id']}", [
            ...$payload,
            'brand_id' => $product['brand']['id'],
            'new_brand_name' => null,
            'variants' => [
                ['id' => $product['variants'][0]['id'], 'name' => '1 L', 'purchase_price' => 300, 'selling_price' => 380],
                ['name' => '4 L', 'purchase_price' => 1100, 'selling_price' => 1350, 'stock_quantity' => 4],
            ],
        ])->assertOk();

        $updated = $this->last();
        $this->assertSame('product.updated', $updated->action->value);
        $this->assertStringStartsWith('Changed product Wall Paint: Selling price (1 L) ₹360.00 → ₹380.00, ', $updated->description);
        $this->assertContains(['field' => 'Selling price (4 L)', 'old' => null, 'new' => '₹1,350.00'], $updated->changes);
        $this->assertContains(['field' => 'Stock (4 L)', 'old' => null, 'new' => '4 pc'], $updated->changes);

        $this->deleteJson("/api/admin/products/{$product['id']}")->assertNoContent();
        $this->assertSame('Deleted product Wall Paint', $this->last()->description);
        $this->assertContains(['field' => 'Selling price (1 L)', 'old' => '₹380.00', 'new' => null], $this->last()->changes);
    }

    public function test_photo_changes_are_logged(): void
    {
        Storage::fake('public');
        $product = $this->itemInStock('Wall Paint')->product;

        $images = $this->post("/api/admin/products/{$product->id}/images", ['images' => [
            UploadedFile::fake()->image('front.jpg', 600, 600),
            UploadedFile::fake()->image('side.jpg', 600, 600),
        ]], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->putJson("/api/admin/products/{$product->id}/images/order", ['ids' => [$images[1]['id'], $images[0]['id']]])->assertOk();
        $this->deleteJson("/api/admin/products/{$product->id}/images/{$images[0]['id']}")->assertOk();

        $this->assertSame(
            ['Added 2 photos to Wall Paint', 'Changed the order of Wall Paint’s photos', 'Removed a photo from Wall Paint'],
            ActivityLog::orderBy('id')->pluck('description')->all(),
        );
    }

    public function test_bills_payments_and_customers_are_logged(): void
    {
        $year = now(config('shop.timezone'))->year;
        $hammer = $this->itemInStock('Claw Hammer', 350);

        $first = $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2]], [
            ['method' => 'cash', 'amount' => 200],
            ['method' => 'upi', 'amount' => 100, 'reference' => 'UPI123456'],
        ], 'MG Road, Jaipur')->assertCreated()->json('data');

        $this->assertSame(['customer.created', 'bill.created'], $this->actions());
        $this->assertSame('Added customer Ramesh Kumar (98765 43210)', ActivityLog::orderBy('id')->first()->description);
        $bill = $this->last();
        $this->assertSame("Made bill INV-{$year}-0001 for Ramesh Kumar: ₹700.00, ₹300.00 paid, ₹400.00 due", $bill->description);
        $this->assertSame(['bill', $first['id'], "INV-{$year}-0001"], [$bill->subject_type, $bill->subject_id, $bill->subject_label]);
        $this->assertSame([
            ['label' => 'Customer', 'value' => 'Ramesh Kumar · 98765 43210 · MG Road, Jaipur'],
            ['label' => 'Items', 'value' => ['Claw Hammer: 2 pc × ₹350.00 = ₹700.00']],
            ['label' => 'Paid', 'value' => ['Cash ₹200.00', 'UPI ₹100.00 (UPI123456)']],
            ['label' => 'Due', 'value' => '₹400.00'],
        ], $bill->details);

        // A known number with a new name: the customer is changed, then the bill made.
        $this->postBill('Ramesh K', '9876543210', [[$hammer, 1]])->assertCreated();
        $this->assertSame(['customer.updated', 'bill.created'], array_slice($this->actions(), -2));
        $this->assertSame('Changed customer Ramesh K: Name Ramesh Kumar → Ramesh K', ActivityLog::orderByDesc('id')->skip(1)->first()->description);
        $this->assertSame("Made bill INV-{$year}-0002 for Ramesh K: ₹350.00, all due", $this->last()->description);

        $this->postJson("/api/admin/bills/{$first['id']}/payments", ['method' => 'upi', 'amount' => 100])->assertOk();
        $this->assertSame("Received ₹100.00 by UPI on bill INV-{$year}-0001: ₹300.00 still due", $this->last()->description);

        $customerId = $first['customer']['id'];
        $this->postJson("/api/admin/customers/{$customerId}/payments", ['method' => 'cash', 'amount' => 650, 'reference' => null])->assertOk();
        $dues = $this->last();
        $this->assertSame('Received ₹650.00 by Cash from Ramesh K towards dues', $dues->description);
        $this->assertSame(['customer', $customerId], [$dues->subject_type, $dues->subject_id]);
        $this->assertSame([
            ['label' => 'Payment', 'value' => 'Cash ₹650.00'],
            ['label' => 'Put towards', 'value' => ["INV-{$year}-0001: ₹300.00", "INV-{$year}-0002: ₹350.00"]],
            ['label' => 'Still due', 'value' => '₹0.00'],
        ], $dues->details);

        // A bill that is refused leaves nothing behind.
        $count = ActivityLog::count();
        $this->postBill('Suresh', '9123456780', [[$hammer, 1]], [['method' => 'cash', 'amount' => 999]])->assertUnprocessable();
        $this->assertSame($count, ActivityLog::count());
    }

    public function test_stock_in_and_corrections_are_logged(): void
    {
        $today = now(config('shop.timezone'));
        $hammer = $this->itemInStock('Claw Hammer', 350, stock: 10);
        $hammer->update(['purchase_price' => 280]);

        $entry = $this->postJson('/api/admin/stock-entries', [
            'supplier_name' => 'Sharma Traders',
            'supplier_bill_number' => 'ST-4512',
            'received_on' => $today->toDateString(),
            'items' => [['product_variant_id' => $hammer->id, 'quantity' => 20, 'purchase_price' => 310]],
        ])->assertCreated()->json('data');

        $this->assertSame('Stock in from Sharma Traders (bill ST-4512): 1 item, ₹6,200.00', $this->last()->description);
        $this->assertSame(['stock_entry', $entry['id'], 'Stock in from Sharma Traders'], [$this->last()->subject_type, $this->last()->subject_id, $this->last()->subject_label]);
        $this->assertSame([
            ['label' => 'Received on', 'value' => $today->format('j M Y')],
            ['label' => 'Items', 'value' => ['Claw Hammer: 20 pc at ₹310.00, average cost ₹280.00 → ₹300.00']],
        ], $this->last()->details);

        $this->postJson("/api/admin/product-variants/{$hammer->id}/stock-corrections", ['quantity' => 28, 'reason' => 'damaged', 'note' => 'Two cracked'])->assertCreated();
        $this->assertSame('Corrected stock of Claw Hammer: 30 pc → 28 pc (Damaged or lost)', $this->last()->description);
        $this->assertSame(['product', $hammer->product_id], [$this->last()->subject_type, $this->last()->subject_id]);

        $this->deleteJson("/api/admin/stock-entries/{$entry['id']}")->assertNoContent();
        $this->assertSame(
            'Deleted stock in from Sharma Traders (bill ST-4512) received '.$today->format('j M Y').'; its stock was taken back out',
            $this->last()->description,
        );
        $this->assertSame([['label' => 'Items', 'value' => ['Claw Hammer: −20 pc, purchase price back to ₹280.00']]], $this->last()->details);
    }

    public function test_the_log_is_filtered_by_admin_section_day_and_search(): void
    {
        config(['shop.timezone' => 'Asia/Kolkata']);
        $suresh = User::factory()->admin()->create(['name' => 'Suresh']);
        $log = app(ActivityLogService::class);

        $this->travelTo(Carbon::parse('2026-11-01 23:30', 'Asia/Kolkata'));
        $log->record(ActivityAction::CategoryCreated, 'Added category Paint', actor: $this->admin);
        // Just after midnight in India, which is still 1 November in UTC.
        $this->travelTo(Carbon::parse('2026-11-02 00:30', 'Asia/Kolkata'));
        $log->record(ActivityAction::LoginFailed, 'Failed login for x@example.com');
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Asia/Kolkata'));
        $log->record(ActivityAction::BrandCreated, 'Added brand Asian Paints', actor: $suresh);

        $shown = fn (string $query) => collect($this->getJson("/api/admin/activity-logs?{$query}")->assertOk()->json('data'))
            ->pluck('description')
            ->all();

        $this->assertSame(['Added brand Asian Paints', 'Failed login for x@example.com', 'Added category Paint'], $shown(''));
        $this->assertSame(['Added brand Asian Paints'], $shown("user_id={$suresh->id}"));
        $this->assertSame(['Failed login for x@example.com'], $shown('section=logins'));
        $this->assertSame(['Added brand Asian Paints', 'Failed login for x@example.com'], $shown('from=2026-11-02&to=2026-11-02'));
        $this->assertSame(['Added category Paint'], $shown('to=2026-11-01'));
        $this->assertSame(['Added brand Asian Paints'], $shown('search=asian'));
        $this->assertSame(['Added brand Asian Paints'], $shown('search=suresh'));

        $this->getJson('/api/admin/activity-logs')->assertJsonPath('data.1.user', null)->assertJsonPath('meta.total', 3);

        $this->getJson('/api/admin/activity-logs?section=parties&to=2026-10-01&from=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section', 'to']);

        $this->getJson('/api/admin/activity-logs/options')
            ->assertOk()
            ->assertJsonPath('data.admins', [['id' => $this->admin->id, 'name' => 'Ravi'], ['id' => $suresh->id, 'name' => 'Suresh']])
            ->assertJsonCount(8, 'data.sections')
            ->assertJsonPath('data.sections.0', ['value' => 'logins', 'label' => 'Logins']);
    }

    public function test_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = app(ActivityLogService::class)->record(ActivityAction::BrandCreated, 'Added brand Asian Paints');

        try {
            $entry->update(['description' => 'Something else']);
            $this->fail('An entry was changed.');
        } catch (LogicException) {
        }

        try {
            $entry->delete();
            $this->fail('An entry was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('Added brand Asian Paints', $entry->fresh()->description);
    }
}
