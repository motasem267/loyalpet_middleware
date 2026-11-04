<?php

namespace Tests\Feature;

use App\Jobs\SyncToERPNext;
use App\Models\Bundle;
use App\Models\Cart;
use App\Models\PendingSync;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['erpnext.url' => 'https://erp.test']);
        Queue::fake();

        $this->user = User::factory()->create(['erp_customer_id' => 'CUST-1']);
        Sanctum::actingAs($this->user);

        Product::create(['erp_name' => 'FOOD', 'item_code' => 'FOOD', 'item_name' => 'أكل', 'price' => 100]);
        Product::create(['erp_name' => 'D005KG', 'item_code' => 'D005KG', 'item_name' => 'نص كيلو', 'price' => 40]);
        Product::create(['erp_name' => 'PKG', 'item_code' => 'PKG', 'item_name' => 'باقة', 'price' => 200]);

        // باقة ظاهرة في التطبيق + باقة وزن مخفية (لازم تُعامل كمنتج عادي)
        Bundle::create(['erp_name' => 'PKG', 'item_code' => 'PKG', 'title' => 'باقة', 'show_in_app' => true]);
        Bundle::create(['erp_name' => 'D005KG', 'item_code' => 'D005KG', 'title' => 'وزن', 'show_in_app' => false]);
    }

    private function fakeCoupon(string $type = 'percentage', float $value = 10): void
    {
        Http::fake([
            'erp.test/api/method/loyalpet.api.coupons.validate_coupon' => Http::response(['message' => [
                'coupon_code' => 'LOYAL10', 'title' => 'خصم', 'discount_type' => $type, 'discount_value' => $value,
            ]]),
        ]);
    }

    public function test_apply_percentage_coupon_and_weight_bundle_is_allowed(): void
    {
        $this->fakeCoupon();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD'])->assertCreated();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'D005KG'])->assertCreated();

        $this->postJson('/api/v1/cart/coupon', ['code' => 'LOYAL10'])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '140.00')
            ->assertJsonPath('data.discount', '14.00')
            ->assertJsonPath('data.total', '126.00')
            ->assertJsonPath('data.coupon.code', 'LOYAL10')
            ->assertJsonPath('data.coupon_allowed', true);
    }

    public function test_fixed_coupon_capped_at_subtotal(): void
    {
        $this->fakeCoupon('amount', 500);
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD']);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'X'])
            ->assertJsonPath('data.discount', '100.00')
            ->assertJsonPath('data.total', '0.00');
    }

    public function test_package_blocks_coupon(): void
    {
        $this->fakeCoupon();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'PKG'])
            ->assertJsonPath('data.coupon_allowed', false);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'LOYAL10'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'الكوبونات ما تنطبقش على الطلبات اللي فيها باقات');

        Http::assertNothingSent();
    }

    public function test_adding_package_removes_coupon(): void
    {
        $this->fakeCoupon();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD']);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'LOYAL10'])->assertOk();

        $this->postJson('/api/v1/cart/items', ['item_code' => 'PKG'])
            ->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.coupon_removed_message', 'تم إزالة الكوبون لأن السلة فيها باقة');
    }

    public function test_erpnext_rejection_message_shown(): void
    {
        Http::fake(['*' => Http::response(['exception' => 'frappe.exceptions.ValidationError: الكوبون منتهي'], 417)]);
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD']);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'OLD'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'الكوبون منتهي');
    }

    public function test_order_carries_coupon_and_discount(): void
    {
        $this->fakeCoupon();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD']);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'LOYAL10']);

        $this->postJson('/api/v1/orders', $this->orderBody())->assertStatus(202);

        $sync = PendingSync::first();
        $this->assertSame('LOYAL10', $sync->payload['coupon_code']);
        $this->assertEquals(10, $sync->payload['discount_amount']);
        Queue::assertPushed(SyncToERPNext::class);

        $this->getJson('/api/v1/orders/'.$sync->id)
            ->assertJsonPath('data.coupon_code', 'LOYAL10')
            ->assertJsonPath('data.grand_total', 90);
    }

    public function test_order_rejected_when_coupon_expired_meanwhile(): void
    {
        $this->fakeCoupon();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD']);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'LOYAL10']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['exception' => 'ValidationError: الكوبون خلص'], 417)]);

        $this->postJson('/api/v1/orders', $this->orderBody())
            ->assertStatus(422)
            ->assertJsonPath('errors.coupon.0', 'الكوبون خلص');

        $this->assertNull(Cart::first()->coupon_code);
        $this->assertSame(0, PendingSync::count());
    }

    public function test_queued_order_edit_remove_coupon_and_package_rule(): void
    {
        $this->fakeCoupon();
        $this->postJson('/api/v1/cart/items', ['item_code' => 'FOOD']);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'LOYAL10']);
        $this->postJson('/api/v1/orders', $this->orderBody());
        $sync = PendingSync::first();

        $this->patchJson('/api/v1/orders/'.$sync->id, ['items' => [['item_code' => 'PKG', 'qty' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'الطلب فيه كوبون، ما تقدرش تضيف باقة. شيل الكوبون أولاً');

        $this->patchJson('/api/v1/orders/'.$sync->id, ['items' => [['item_code' => 'FOOD', 'qty' => 2]]])->assertOk();
        $this->assertEquals(20, $sync->fresh()->payload['discount_amount']);

        $this->patchJson('/api/v1/orders/'.$sync->id, ['coupon_code' => null])->assertOk();
        $this->assertNull($sync->fresh()->payload['coupon_code']);
        $this->assertEquals(0, $sync->fresh()->payload['discount_amount']);
    }

    private function orderBody(): array
    {
        return [
            'payment_method' => array_key_first(config('payment.methods')),
            'recipient_name' => 'زبونة',
            'recipient_phone' => '0910000000',
            'delivery_address' => 'طرابلس',
            'delivery_date' => now()->toDateString(),
        ];
    }
}
