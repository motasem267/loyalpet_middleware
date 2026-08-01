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
}
