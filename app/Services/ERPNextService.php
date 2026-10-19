<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ERPNextService
{
    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = (string) config('erpnext.url');
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'token '.config('erpnext.api_key').':'.config('erpnext.api_secret'),
            'Content-Type' => 'application/json',
        ])->baseUrl($this->baseUrl);
    }

    /**
     * رفع ملف (مثلًا إيصال الحوالة) كملف خاص (is_private) — بيرجع file_url اللي
     * بيتبعت لـ create_sales_order. client() فيه Content-Type: json فبنبني طلب multipart منفصل.
     */
    public function uploadFile(string $path, string $filename): string
    {
        return Http::withHeaders([
            'Authorization' => 'token '.config('erpnext.api_key').':'.config('erpnext.api_secret'),
        ])
            ->baseUrl($this->baseUrl)
            ->attach('file', file_get_contents($path), $filename)
            ->post('/api/method/upload_file', ['is_private' => 1])
            ->throw()
            ->json('message.file_url');
    }

    /**
     * تحميل ملف خاص من ERPNext (/private/files/...) — التطبيق ما يقدرش يفتحه مباشرة
     * لأنه محتاج توكن ERPNext، فلارافيل بيجيبه ويعدّيه.
     *
     * @return array{body: string, content_type: string}
     */
    public function downloadFile(string $fileUrl): array
    {
        $response = $this->client()->get($fileUrl)->throw();

        return [
            'body' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'application/octet-stream',
        ];
    }

    public function getList(string $doctype, array $filters = [], array $fields = [], int $limit = 20, int $offset = 0): array
    {
        return $this->client()->get('/api/resource/'.$doctype, [
            'filters' => json_encode($filters),
            'fields' => json_encode($fields),
            'limit_page_length' => $limit,
            'limit_start' => $offset,
        ])->throw()->json('data');
    }

    /**
     * يسحب كل السجلات صفحة صفحة (لسحب كامل الشجرة/الكتالوج بدون حد أقصى ثابت).
     */
    public function getAll(string $doctype, array $filters = [], array $fields = [], int $pageSize = 100): array
    {
        $results = [];
        $offset = 0;

        do {
            $page = $this->getList($doctype, $filters, $fields, $pageSize, $offset);
            $results = array_merge($results, $page);
            $offset += $pageSize;
        } while (count($page) === $pageSize);

        return $results;
    }

    public function get(string $doctype, string $name): array
    {
        return $this->client()->get("/api/resource/{$doctype}/{$name}")->throw()->json('data');
    }

    public function create(string $doctype, array $data): array
    {
        return $this->client()->post("/api/resource/{$doctype}", $data)->throw()->json('data');
    }

    public function update(string $doctype, string $name, array $data): array
    {
        return $this->client()->put("/api/resource/{$doctype}/{$name}", $data)->throw()->json('data');
    }

    public function delete(string $doctype, string $name): void
    {
        $this->client()->delete("/api/resource/{$doctype}/{$name}")->throw();
    }

    public function callMethod(string $method, array $params = []): mixed
    {
        return $this->client()->post("/api/method/{$method}", $params)->throw()->json();
    }

    /**
     * القيم الممكنة لخاصية variant (زي "دجاج"، "تونة" لخاصية "Wanby Flavors") —
     * مش موجودة على المنتج (Template) نفسه، متخزّنة في Doctype منفصل "Item Attribute".
     */
    public function getItemAttributeValues(string $attributeName): array
    {
        $doc = $this->get('Item Attribute', $attributeName);

        return collect($doc['item_attribute_values'] ?? [])
            ->pluck('attribute_value')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Frappe بيحط رسالة frappe.throw في _server_messages (JSON string لـ list من JSON
     * strings، كل واحدة فيها message) — أو في exception كـ "frappe.exceptions.X: الرسالة".
     */
    public static function errorMessage(array $body): ?string
    {
        foreach (json_decode($body['_server_messages'] ?? '[]', true) ?: [] as $raw) {
            $message = json_decode($raw, true)['message'] ?? null;

            if ($message) {
                return strip_tags($message);
            }
        }

        if (isset($body['exception'])) {
            return trim(preg_replace('/^[\w.]+:\s*/', '', $body['exception']));
        }

        return null;
    }
}
