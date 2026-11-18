<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['type' => 'nullable|string|max:255', 'id' => 'nullable|integer|min:1', 'from' => 'nullable|date', 'to' => $request->filled('from') ? 'nullable|date|after_or_equal:from' : 'nullable|date']);
        $events = AuditEvent::query()->when($request->filled('type'), fn ($q) => $q->where('auditable_type', $request->string('type')))->when($request->filled('id'), fn ($q) => $q->where('auditable_id', $request->integer('id')))->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))->orderByDesc('created_at')->limit(500)->get();

        return view('audit-events.index', compact('events'));
    }
}
