<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ServiceCategoryController extends Controller
{
    public function index()
    {
        $categories = ServiceCategory::orderBy('name')
            ->withCount('serviceRequests')
            ->get();

        return Inertia::render('Admin/ServiceCategories/Index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120|unique:service_categories,name',
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:80',
            'is_active'   => 'nullable|boolean',
        ]);
        $data['is_active'] = $data['is_active'] ?? true;

        ServiceCategory::create($data);

        return back()->with('success', 'Service category created.');
    }

    public function update(Request $request, ServiceCategory $serviceCategory)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120|unique:service_categories,name,' . $serviceCategory->id,
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:80',
            'is_active'   => 'nullable|boolean',
        ]);

        $serviceCategory->update($data);

        return back()->with('success', 'Service category updated.');
    }

    public function destroy(ServiceCategory $serviceCategory)
    {
        if ($serviceCategory->serviceRequests()->exists()) {
            return back()->with('error', 'Cannot delete: category is in use by ' . $serviceCategory->serviceRequests()->count() . ' service request(s). Deactivate it instead.');
        }

        $serviceCategory->delete();
        return back()->with('success', 'Service category deleted.');
    }
}
