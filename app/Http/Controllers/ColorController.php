<?php

namespace App\Http\Controllers;

use App\Models\Color;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:colors,name',
        ], [
            'name.unique' => 'Warna ini sudah ditambahkan.',
        ]);

        $color = Color::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Warna berhasil ditambahkan.',
            'color' => $color,
        ]);
    }
}
