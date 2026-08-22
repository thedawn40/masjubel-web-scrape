<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = Source::withCount('goldPrices')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Success fetch all sources data',
            'data' => $sources,
        ], 200);
    }

    public function show(Source $source): JsonResponse
    {
        $source->loadCount('goldPrices');

        return response()->json([
            'status' => 'success',
            'message' => "Success fetch {$source->name} source data",
            'data' => $source,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:sources,slug'],
            'url' => ['required', 'string', 'url', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_commodity' => ['nullable', 'boolean'],
        ]);

        $validated['slug'] = isset($validated['slug'])
            ? $validated['slug']
            : $this->generateUniqueSlug(Str::slug($validated['name']));

        $source = Source::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'url' => $validated['url'],
            'is_active' => $validated['is_active'] ?? true,
            'is_commodity' => $validated['is_commodity'] ?? false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Success create {$source->name} source",
            'data' => $source,
        ], 201);
    }

    public function update(Request $request, Source $source): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('sources', 'slug')->ignore($source->id),
            ],
            'url' => ['required', 'string', 'url', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_commodity' => ['nullable', 'boolean'],
        ]);

        $source->update([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'is_active' => $validated['is_active'] ?? $source->is_active,
            'is_commodity' => $validated['is_commodity'] ?? $source->is_commodity,
            // Slug hanya berubah jika eksplisit dikirim, agar referensi scraper tidak putus
            'slug' => $validated['slug'] ?? $source->slug,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Success update {$source->name} source",
            'data' => $source->fresh(),
        ], 200);
    }

    public function destroy(Source $source): JsonResponse
    {
        if ($source->goldPrices()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete {$source->name} source because it still has price data",
            ], 409);
        }

        $source->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Success delete {$source->name} source",
        ], 200);
    }

    /**
     * Generate slug unik dengan menambahkan suffix angka jika sudah dipakai.
     */
    private function generateUniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 2;

        while (Source::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        return $slug;
    }
}
