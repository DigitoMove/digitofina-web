@csrf
<section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    <div class="grid gap-4 md:grid-cols-2">
        <label class="text-sm font-medium text-slate-700">Amount<input type="number" min="100" step="100" name="amount" value="{{ old('amount') }}" class="mt-1 w-full rounded border-slate-300" required></label>
        <label class="text-sm font-medium text-slate-700">Payment method<select name="payment_method" class="mt-1 w-full rounded border-slate-300">@foreach(['cash','mobile_money','bank','cheque','internal_transfer'] as $method)<option value="{{ $method }}" @selected(old('payment_method', 'cash') === $method)>{{ str($method)->headline() }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-slate-700 md:col-span-2">Narration<textarea name="narration" class="mt-1 w-full rounded border-slate-300" rows="3">{{ old('narration') }}</textarea></label>
    </div>
    <button class="mt-5 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">{{ $button }}</button>
</section>
