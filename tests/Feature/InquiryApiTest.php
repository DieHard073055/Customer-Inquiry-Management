<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\InquiryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InquiryApiTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'     => 'Ahmed Rasheed',
            'email'    => 'ahmed@mse.mv',
            'phone'    => '+960 123 4567',
            'category' => 'trading',
            'subject'  => 'Order not executing',
            'message'  => 'My buy order for 1000 shares has been pending for over an hour.',
        ], $overrides);
    }

    private function postInquiry(array $overrides = [])
    {
        return $this->postJson('/api/inquiries', $this->validPayload($overrides));
    }

    // ---------------------------------------------------------------------------
    // POST /api/inquiries – store
    // ---------------------------------------------------------------------------

    public function test_store_creates_inquiry_and_returns_201(): void
    {
        $response = $this->postInquiry();

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'reference_number',
                    'name',
                    'email',
                    'phone',
                    'category',
                    'subject',
                    'message',
                    'status',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseCount('inquiries', 1);
    }

    public function test_store_persists_all_fields_correctly(): void
    {
        $this->postInquiry();

        $this->assertDatabaseHas('inquiries', [
            'name'     => 'Ahmed Rasheed',
            'email'    => 'ahmed@mse.mv',
            'phone'    => '+960 123 4567',
            'category' => 'trading',
            'subject'  => 'Order not executing',
            'status'   => 'open',
        ]);
    }

    public function test_store_generates_unique_reference_number(): void
    {
        $this->postInquiry();
        $this->postInquiry();

        $refs = Inquiry::pluck('reference_number');

        $this->assertCount(2, $refs);
        $this->assertNotEquals($refs[0], $refs[1]);
        $this->assertMatchesRegularExpression('/^MSE-[A-Z0-9]{8}$/', $refs[0]);
        $this->assertMatchesRegularExpression('/^MSE-[A-Z0-9]{8}$/', $refs[1]);
    }

    public function test_store_defaults_status_to_open(): void
    {
        $response = $this->postInquiry();

        $response->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('inquiries', ['status' => 'open']);
    }

    public function test_store_creates_audit_log_entry(): void
    {
        $this->postInquiry();

        $inquiry = Inquiry::first();

        $this->assertDatabaseCount('inquiry_logs', 1);
        $this->assertDatabaseHas('inquiry_logs', [
            'inquiry_id' => $inquiry->id,
            'event'      => 'inquiry_created',
        ]);
    }

    public function test_store_audit_log_contains_correct_context(): void
    {
        $this->postInquiry();

        $log = InquiryLog::first();

        $this->assertEquals('trading', $log->context['category']);
        $this->assertEquals('Order not executing', $log->context['subject']);
    }

    public function test_store_writes_to_log_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('daily')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Inquiry created'
                    && isset($context['reference_number'])
                    && $context['category'] === 'trading'
                    && $context['email'] === 'ahmed@mse.mv';
            });

        $this->postInquiry();
    }

    public function test_store_phone_is_optional(): void
    {
        $response = $this->postJson('/api/inquiries', $this->validPayload(['phone' => null]));

        $response->assertStatus(201)
            ->assertJsonPath('data.phone', null);
    }

    public function test_store_accepts_all_valid_categories(): void
    {
        $categories = ['trading', 'market_data', 'technical_issues', 'general_questions'];

        foreach ($categories as $category) {
            $response = $this->postInquiry(['category' => $category]);
            $response->assertStatus(201)->assertJsonPath('data.category', $category);
        }

        $this->assertDatabaseCount('inquiries', 4);
    }

    public function test_store_rolls_back_if_service_throws(): void
    {
        // Mock the service to throw — controller must catch it and return 500
        $this->mock(\App\Services\InquiryService::class)
            ->shouldReceive('store')
            ->once()
            ->andThrow(new \RuntimeException('Unexpected DB error'));

        $this->postInquiry()
            ->assertStatus(500)
            ->assertJson(['message' => 'Failed to submit inquiry. Please try again.']);

        $this->assertDatabaseCount('inquiries', 0);
    }

    public function test_store_transaction_rolls_back_on_audit_log_failure(): void
    {
        // Drop the audit log table mid-request to force a real transaction rollback
        DB::statement('DROP TABLE inquiry_logs');

        $this->postInquiry()->assertStatus(500);

        // The inquiry itself must NOT have been committed
        $this->assertDatabaseCount('inquiries', 0);
    }

    // ---------------------------------------------------------------------------
    // POST /api/inquiries – validation
    // ---------------------------------------------------------------------------

    public function test_store_requires_name(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_valid_email(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_store_requires_email(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_store_rejects_invalid_category(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['category' => 'invalid_category']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category'])
            ->assertJsonPath(
                'errors.category.0',
                'Category must be one of: trading, market_data, technical_issues, general_questions.'
            );
    }

    public function test_store_requires_category(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['category' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_store_requires_subject(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['subject' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);
    }

    public function test_store_requires_message(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['message' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_store_rejects_message_shorter_than_10_chars(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['message' => 'Too short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message'])
            ->assertJsonPath('errors.message.0', 'The message must be at least 10 characters.');
    }

    public function test_store_rejects_message_longer_than_5000_chars(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['message' => str_repeat('a', 5001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_store_rejects_name_longer_than_255_chars(): void
    {
        $this->postJson('/api/inquiries', $this->validPayload(['name' => str_repeat('a', 256)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_returns_422_with_all_fields_missing(): void
    {
        $this->postJson('/api/inquiries', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'category', 'subject', 'message']);
    }

    // ---------------------------------------------------------------------------
    // GET /api/inquiries – index
    // ---------------------------------------------------------------------------

    public function test_index_returns_empty_list_when_no_inquiries(): void
    {
        $this->getJson('/api/inquiries')
            ->assertStatus(200)
            ->assertJson([
                'data' => [],
                'meta' => ['total' => 0],
            ]);
    }

    public function test_index_returns_all_inquiries(): void
    {
        $this->postInquiry();
        $this->postInquiry(['email' => 'other@mse.mv']);

        $this->getJson('/api/inquiries')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_returns_correct_pagination_structure(): void
    {
        $this->postInquiry();

        $this->getJson('/api/inquiries')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);
    }

    public function test_index_paginates_results(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postInquiry(['email' => "user{$i}@mse.mv"]);
        }

        $this->getJson('/api/inquiries?per_page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_index_filters_by_category(): void
    {
        $this->postInquiry(['category' => 'trading']);
        $this->postInquiry(['email' => 'b@mse.mv', 'category' => 'market_data']);
        $this->postInquiry(['email' => 'c@mse.mv', 'category' => 'market_data']);

        $this->getJson('/api/inquiries?category=market_data')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_filters_by_status(): void
    {
        $this->postInquiry();
        $this->postInquiry(['email' => 'b@mse.mv']);

        // Manually set one to resolved
        Inquiry::first()->update(['status' => 'resolved']);

        $this->getJson('/api/inquiries?status=open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/inquiries?status=resolved')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_returns_newest_first(): void
    {
        $this->travel(-10)->seconds();
        $this->postInquiry(['subject' => 'First inquiry']);
        $this->travelBack();

        $this->postInquiry(['email' => 'b@mse.mv', 'subject' => 'Second inquiry']);

        $response = $this->getJson('/api/inquiries')->assertStatus(200);

        $this->assertEquals('Second inquiry', $response->json('data.0.subject'));
        $this->assertEquals('First inquiry', $response->json('data.1.subject'));
    }

    // ---------------------------------------------------------------------------
    // GET /api/inquiries/{id} – show
    // ---------------------------------------------------------------------------

    public function test_show_returns_inquiry_by_id(): void
    {
        $this->postInquiry();
        $inquiry = Inquiry::first();

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $inquiry->id)
            ->assertJsonPath('data.reference_number', $inquiry->reference_number)
            ->assertJsonPath('data.name', 'Ahmed Rasheed')
            ->assertJsonPath('data.email', 'ahmed@mse.mv')
            ->assertJsonPath('data.category', 'trading');
    }

    public function test_show_returns_404_for_nonexistent_inquiry(): void
    {
        $this->getJson('/api/inquiries/9999')
            ->assertStatus(404);
    }

    public function test_show_does_not_expose_ip_address(): void
    {
        $this->postInquiry();
        $inquiry = Inquiry::first();

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertStatus(200)
            ->assertJsonMissingPath('data.ip_address');
    }
}
