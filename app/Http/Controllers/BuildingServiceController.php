<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BuildingServiceController extends Controller
{
    public function index(): View
    {
        return view('services', ['services' => $this->services()]);
    }

    public function settings(): View
    {
        return view('admin.services', ['services' => $this->services()]);
    }

    public function edit(?int $service = null): View
    {
        $record = $service === null ? null : DB::table('building_services')->find($service);
        abort_if($service !== null && ! $record, 404);

        return view('admin.service-edit', ['service' => $record ? $this->decode($record) : null]);
    }

    public function save(Request $request, ?int $service = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200', 'category' => 'required|string|max:100',
            'contact' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:254', 'website' => 'nullable|url:http,https|max:2000',
            'account' => 'nullable|string|max:500', 'username' => 'nullable|string|max:500',
            'service_password' => 'nullable|string|max:2000', 'clear_password' => 'nullable|boolean',
            'notes' => 'nullable|string|max:10000',
        ]);
        DB::transaction(function () use ($data, $service) {
            $record = $service === null ? null : DB::table('building_services')->where('id', $service)->lockForUpdate()->first();
            abort_if($service !== null && ! $record, 404);
            $previous = $record ? $this->decode($record) : [];
            $details = [];
            foreach (['contact', 'phone', 'email', 'website', 'account', 'username', 'notes'] as $field) {
                $details[$field] = $data[$field] ?? '';
            }
            $details['password'] = ($data['clear_password'] ?? false) ? '' : ($data['service_password'] ?? $previous['password'] ?? '');
            $values = ['name' => $data['name'], 'category' => $data['category'], 'details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'updated_at' => now()];
            if ($record) {
                DB::table('building_services')->where('id', $service)->update($values);
            } else {
                $service = DB::table('building_services')->insertGetId($values + ['created_at' => now()]);
            }
            Audit::record($record ? 'service.updated' : 'service.created', 'service:'.$service);
        });

        return redirect()->route('admin.services')->with('status', 'Building service saved. Residents can see the updated details.');
    }

    public function delete(int $service): RedirectResponse
    {
        DB::transaction(function () use ($service) {
            abort_unless(DB::table('building_services')->where('id', $service)->delete(), 404);
            Audit::record('service.deleted', 'service:'.$service);
        });

        return redirect()->route('admin.services')->with('status', 'Building service removed.');
    }

    private function services(): Collection
    {
        return DB::table('building_services')->orderBy('category')->orderBy('name')->get()->map(fn (object $service) => $this->decode($service));
    }

    private function decode(object $service): array
    {
        return ['id' => $service->id, 'name' => $service->name, 'category' => $service->category, 'updated_at' => $service->updated_at] + json_decode(Crypt::decryptString($service->details), true, flags: JSON_THROW_ON_ERROR);
    }
}
