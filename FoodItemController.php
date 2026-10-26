<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FoodItem;
use App\Models\FoodLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class FoodItemController extends Controller
{
    /**
     * Display the dashboard home page with search, categories, and inventory.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        // Fetch categories
        $categories = Category::orderBy('name')->get();

        // Build base query for user's active food items
        $query = FoodItem::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('category');

        // Apply Search Filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        // Apply Category Filter
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        // Apply Storage Location Filter
        if ($request->filled('storage_location')) {
            $query->where('storage_location', $request->input('storage_location'));
        }

        // Get items ordered by expiry date (soonest first)
        $foodItems = $query->orderBy('expiry_date', 'asc')->get();

        // ─── Calculate Dashboard Stats ───
        $totalItemsCount = FoodItem::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        // Expiring in next 7 days
        $expiringSoonCount = FoodItem::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereBetween('expiry_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        // Expired items (active but past expiry date)
        $expiredCount = FoodItem::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('expiry_date', '<', now()->startOfDay())
            ->count();

        // Wasted items count
        $wastedCount = FoodItem::where('user_id', $user->id)
            ->where('status', 'wasted')
            ->count();

        return view('dashboard', compact(
            'categories',
            'foodItems',
            'totalItemsCount',
            'expiringSoonCount',
            'expiredCount',
            'wastedCount'
        ));
    }

    /**
     * Store a newly created food item in database.
     */
    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:100'],
            'category_id' => ['required', 'exists:categories,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['required', 'in:kg,g,lb,oz,L,mL,pcs,bottle,can,box,bag,pack'],
            'purchase_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'storage_location' => ['required', 'in:fridge,freezer,pantry,shelf,cupboard,other'],
            'notes' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,bmp', 'max:5120'], // Max 5MB
        ];

        if ($request->filled('purchase_date') && $request->filled('expiry_date')) {
            $rules['expiry_date'][] = 'after_or_equal:purchase_date';
        }

        $validated = $request->validate($rules);

        // If expiry_date is not provided, calculate predefined date based on Category & Storage Location
        if (empty($validated['expiry_date'])) {
            $category = Category::find($validated['category_id']);
            $validated['expiry_date'] = $this->calculatePredefinedExpiryDate(
                $category?->slug,
                $validated['storage_location'],
                $validated['purchase_date'] ?? null,
                $validated['name']
            );
        }

        $validated['user_id'] = Auth::id();
        $validated['status'] = 'active';

        // Handle Image Upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('food_items', 'public');
            $validated['image'] = $path;
        }

        // Create Item
        $foodItem = FoodItem::create($validated);

        // Log Action
        FoodLog::create([
            'user_id' => Auth::id(),
            'food_item_id' => $foodItem->id,
            'action' => 'added',
            'quantity_before' => null,
            'quantity_after' => $foodItem->quantity,
            'notes' => 'Item initially added to inventory.',
            'logged_at' => now(),
        ]);

        return redirect()->route('dashboard')
            ->with('success', "Food item '{$foodItem->name}' added successfully!");
    }

    /**
     * Mark a food item as Consumed (used).
     */
    public function consume(FoodItem $foodItem): RedirectResponse
    {
        if ($foodItem->user_id !== Auth::id()) {
            abort(403);
        }

        $qtyBefore = $foodItem->quantity;
        $foodItem->status = 'used';
        $foodItem->save();

        // Log Action
        FoodLog::create([
            'user_id' => Auth::id(),
            'food_item_id' => $foodItem->id,
            'action' => 'used',
            'quantity_before' => $qtyBefore,
            'quantity_after' => 0,
            'notes' => 'Item marked as consumed.',
            'logged_at' => now(),
        ]);

        return redirect()->route('dashboard')
            ->with('success', "Marked '{$foodItem->name}' as consumed! Thank you for reducing waste.");
    }

    /**
     * Mark a food item as Wasted (thrown away).
     */
    public function waste(FoodItem $foodItem): RedirectResponse
    {
        if ($foodItem->user_id !== Auth::id()) {
            abort(403);
        }

        $qtyBefore = $foodItem->quantity;
        $foodItem->status = 'wasted';
        $foodItem->save();

        // Log Action
        FoodLog::create([
            'user_id' => Auth::id(),
            'food_item_id' => $foodItem->id,
            'action' => 'wasted',
            'quantity_before' => $qtyBefore,
            'quantity_after' => 0,
            'notes' => 'Item marked as wasted.',
            'logged_at' => now(),
        ]);

        return redirect()->route('dashboard')
            ->with('warning', "Marked '{$foodItem->name}' as wasted. Let's try to consume it next time!");
    }

    /**
     * Delete a food item (soft delete).
     */
    public function destroy(FoodItem $foodItem): RedirectResponse
    {
        if ($foodItem->user_id !== Auth::id()) {
            abort(403);
        }

        // Delete image if exists
        if ($foodItem->image) {
            Storage::disk('public')->delete($foodItem->image);
        }

        // Log Action
        FoodLog::create([
            'user_id' => Auth::id(),
            'food_item_id' => $foodItem->id,
            'action' => 'deleted',
            'quantity_before' => $foodItem->quantity,
            'quantity_after' => null,
            'notes' => 'Item deleted from inventory.',
            'logged_at' => now(),
        ]);

        $foodItem->delete();

        return redirect()->route('dashboard')
            ->with('success', 'Food item removed successfully.');
    }

    /**
     * Calculate predefined expiry date based on Food Expiration Dates Guidelines Chart.
     */
    protected function calculatePredefinedExpiryDate(?string $categorySlug, string $storageLocation, ?string $purchaseDate = null, ?string $itemName = null): string
    {
        $baseDate = $purchaseDate ? Carbon::parse($purchaseDate) : Carbon::today();
        $location = strtolower($storageLocation);
        $days = null;

        if ($itemName) {
            $n = strtolower($itemName);

            // 1. Eggs
            if (str_contains($n, 'egg')) {
                if (str_contains($n, 'cooked')) {
                    $days = ($location === 'fridge') ? 7 : (($location === 'freezer') ? 7 : 1);
                } elseif (str_contains($n, 'dried') || str_contains($n, 'dry')) {
                    $days = ($location === 'fridge') ? 30 : 730;
                } else {
                    // Fresh in shell
                    $days = ($location === 'fridge') ? 28 : (($location === 'freezer') ? 7 : 7);
                }
            }
            // 2. Fresh Meat (Steak, Chop, Roast, Ground/Hamburger, Sausage, Bacon)
            elseif (str_contains($n, 'steak')) {
                $days = ($location === 'freezer') ? 270 : (($location === 'fridge') ? 4 : 1);
            } elseif (str_contains($n, 'chop')) {
                $days = ($location === 'freezer') ? 150 : (($location === 'fridge') ? 4 : 1);
            } elseif (str_contains($n, 'roast')) {
                $days = ($location === 'freezer') ? 240 : (($location === 'fridge') ? 4 : 1);
            } elseif (str_contains($n, 'bacon')) {
                $days = ($location === 'freezer') ? 30 : (($location === 'fridge') ? 7 : 1);
            } elseif (str_contains($n, 'hamburger') || str_contains($n, 'ground') || str_contains($n, 'mince')) {
                $days = ($location === 'freezer') ? 100 : (($location === 'fridge') ? 2 : 1);
            } elseif (str_contains($n, 'sausage') || str_contains($n, 'hot dog') || str_contains($n, 'hotdog') || str_contains($n, 'ham')) {
                $days = ($location === 'freezer') ? 45 : (($location === 'fridge') ? 7 : 1);
            }
            // 3. Poultry (Chicken, Turkey, Duck, Nuggets)
            elseif (str_contains($n, 'chicken') || str_contains($n, 'turkey') || str_contains($n, 'poultry') || str_contains($n, 'nugget')) {
                if (str_contains($n, 'cooked') || str_contains($n, 'fried') || str_contains($n, 'nugget')) {
                    $days = ($location === 'freezer') ? 120 : (($location === 'fridge') ? 4 : 1);
                } else {
                    $days = ($location === 'freezer') ? 270 : (($location === 'fridge') ? 2 : 1);
                }
            }
            // 4. Seafood (Fish, Salmon, Tuna, Shrimp, Prawn, Squid, Shellfish, Crab)
            elseif (str_contains($n, 'fish') || str_contains($n, 'salmon') || str_contains($n, 'tuna') || str_contains($n, 'shrimp') || str_contains($n, 'prawn') || str_contains($n, 'squid') || str_contains($n, 'shellfish') || str_contains($n, 'seafood') || str_contains($n, 'crab')) {
                if (str_contains($n, 'canned') || str_contains($n, 'tinned') || str_contains($n, 'can')) {
                    $days = ($location === 'fridge') ? 4 : 365;
                } elseif (str_contains($n, 'cooked')) {
                    $days = ($location === 'freezer') ? 150 : (($location === 'fridge') ? 4 : 1);
                } else {
                    $days = ($location === 'freezer') ? 180 : (($location === 'fridge') ? 2 : 1);
                }
            }
            // 5. Canned goods / Soup
            elseif (str_contains($n, 'canned') || str_contains($n, 'soup') || str_contains($n, 'tin')) {
                $days = ($location === 'fridge') ? 4 : 730;
            }
            // 6. Milk & Bread
            elseif (str_contains($n, 'milk')) {
                $days = ($location === 'freezer') ? 60 : (($location === 'fridge') ? 7 : 2);
            } elseif (str_contains($n, 'bread') || str_contains($n, 'bun')) {
                $days = ($location === 'freezer') ? 90 : (($location === 'fridge') ? 12 : 4);
            }
        }

        if ($days === null) {
            $matrix = [
                'dairy-eggs' => ['pantry' => 2, 'shelf' => 2, 'cupboard' => 2, 'fridge' => 7, 'freezer' => 60, 'default' => 7],
                'bakery-bread' => ['pantry' => 4, 'shelf' => 4, 'cupboard' => 4, 'fridge' => 12, 'freezer' => 90, 'default' => 5],
                'vegetables' => ['pantry' => 5, 'shelf' => 5, 'cupboard' => 5, 'fridge' => 12, 'freezer' => 120, 'default' => 10],
                'fruits' => ['pantry' => 5, 'shelf' => 5, 'cupboard' => 5, 'fridge' => 10, 'freezer' => 90, 'default' => 7],
                'seafood' => ['pantry' => 1, 'shelf' => 1, 'cupboard' => 1, 'fridge' => 2, 'freezer' => 180, 'default' => 2],
                'meat-poultry' => ['pantry' => 1, 'shelf' => 1, 'cupboard' => 1, 'fridge' => 3, 'freezer' => 180, 'default' => 3],
                'herbs-spices' => ['pantry' => 180, 'shelf' => 180, 'cupboard' => 180, 'fridge' => 14, 'freezer' => 180, 'default' => 180],
                'grains-cereals' => ['pantry' => 180, 'shelf' => 180, 'cupboard' => 180, 'fridge' => 240, 'freezer' => 365, 'default' => 180],
                'legumes-nuts' => ['pantry' => 180, 'shelf' => 180, 'cupboard' => 180, 'fridge' => 240, 'freezer' => 365, 'default' => 180],
                'condiments-sauces' => ['pantry' => 90, 'shelf' => 90, 'cupboard' => 90, 'fridge' => 180, 'freezer' => 365, 'default' => 90],
                'oils-fats' => ['pantry' => 180, 'shelf' => 180, 'cupboard' => 180, 'fridge' => 240, 'freezer' => 365, 'default' => 180],
                'snacks-sweets' => ['pantry' => 90, 'shelf' => 90, 'cupboard' => 90, 'fridge' => 120, 'freezer' => 180, 'default' => 90],
                'canned-packaged' => ['pantry' => 365, 'shelf' => 365, 'cupboard' => 365, 'fridge' => 365, 'freezer' => 365, 'default' => 365],
                'frozen-foods' => ['pantry' => 14, 'shelf' => 14, 'cupboard' => 14, 'fridge' => 14, 'freezer' => 180, 'default' => 180],
                'beverages' => ['pantry' => 14, 'shelf' => 14, 'cupboard' => 14, 'fridge' => 30, 'freezer' => 180, 'default' => 30],
            ];

            $catRules = $matrix[$categorySlug] ?? ['pantry' => 7, 'fridge' => 14, 'freezer' => 90, 'default' => 14];
            $days = $catRules[$location] ?? $catRules['default'] ?? 14;
        }

        return $baseDate->copy()->addDays($days)->toDateString();
    }
}
