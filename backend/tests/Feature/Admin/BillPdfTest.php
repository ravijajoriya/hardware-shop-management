<?php

namespace Tests\Feature\Admin;

use App\Models\Bill;
use App\Models\User;
use App\Services\BillPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Admin\Concerns\MakesBills;
use Tests\TestCase;

class BillPdfTest extends TestCase
{
    use MakesBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_and_non_admins_cannot_get_a_bill_pdf(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 1]])->json('data');
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/admin/bills/{$bill['id']}/pdf")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/admin/bills/{$bill['id']}/pdf")->assertForbidden();
    }

    public function test_a_bill_comes_as_a_pdf_named_after_its_number(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 2]])->json('data');

        $response = $this->get("/api/admin/bills/{$bill['id']}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "inline; filename=\"{$bill['bill_number']}.pdf\"");

        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->getJson('/api/admin/bills/999/pdf')->assertNotFound();
    }

    public function test_each_bill_gets_its_own_private_pdf_link(): void
    {
        $item = $this->itemInStock();

        $first = $this->postBill('Ramesh Kumar', '9876543210', [[$item, 1]])->json('data.share_url');
        $second = $this->postBill('Suresh Patel', '9123456780', [[$item, 1]])->json('data.share_url');

        $this->assertStringStartsWith(url('/bill/'), $first);
        $this->assertMatchesRegularExpression('#/bill/[A-Za-z0-9]{40}$#', $first);
        $this->assertNotSame($first, $second);
        $this->getJson('/api/admin/bills')->assertJsonPath('data.1.share_url', $first);
    }

    public function test_the_private_link_opens_the_pdf_without_logging_in(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 2]])->json('data');
        $this->app['auth']->forgetGuards();

        $response = $this->get($bill['share_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "inline; filename=\"{$bill['bill_number']}.pdf\"")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_a_wrong_or_shortened_link_finds_nothing(): void
    {
        $bill = $this->postBill('Ramesh Kumar', '9876543210', [[$this->itemInStock(), 1]])->json('data');
        $token = basename($bill['share_url']);
        $this->app['auth']->forgetGuards();

        $this->get('/bill/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/bill/'.substr($token, 0, 39))->assertNotFound();
        $this->get('/bill/'.$bill['id'])->assertNotFound();
    }

    public function test_the_pdf_shows_the_bill_as_printed(): void
    {
        config([
            'shop.name' => 'Sharma Hardware',
            'shop.address' => 'MG Road, Jaipur',
            'shop.phone' => null,
            'shop.timezone' => 'Asia/Kolkata',
        ]);
        $this->travelTo(Carbon::parse('2026-11-02 15:45', 'Asia/Kolkata'));

        $hammer = $this->itemInStock('Claw Hammer', 350);
        $nails = $this->itemInStock('Wire Nails', 120, decimals: true, size: '2 inch');
        $this->postBill('Ramesh Kumar', '9876543210', [[$hammer, 2], [$nails, 1.5, 110]], [
            ['method' => 'cash', 'amount' => 200],
            ['method' => 'upi', 'amount' => 100, 'reference' => 'UPI123456'],
        ], address: '12, Station Road, Jaipur');

        $html = app(BillPdfService::class)->html(Bill::firstOrFail());

        foreach ([
            'Sharma Hardware', 'MG Road, Jaipur',
            'INV-2026-0001', '2 Nov 2026, 3:45 pm',
            'Ramesh Kumar', '98765 43210', '12, Station Road, Jaipur', 'Partly paid',
            'Claw Hammer', '2 pc', '₹350', '₹700',
            'Wire Nails', '2 inch', '1.5 kg', '₹110', '₹165',
            'Cash', 'UPI · UPI123456',
            '₹865', '₹300', '₹565',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // No phone number is set, so no empty "Phone:" line.
        $this->assertStringNotContainsString('Phone:', $html);
    }
}
