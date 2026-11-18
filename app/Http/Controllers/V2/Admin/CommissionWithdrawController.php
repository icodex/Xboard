<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CommissionWithdraw;
use App\Services\CommissionWithdrawService;
use Illuminate\Http\Request;

class CommissionWithdrawController extends Controller
{
    public function __construct(private readonly CommissionWithdrawService $service)
    {
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = CommissionWithdraw::with(['user:id,email', 'ticket:id,subject'])
            ->orderBy('id', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $records = $query->paginate($this->perPage($request, 20, 200));
        $records->getCollection()->transform(function (CommissionWithdraw $withdraw) {
            $row = $withdraw->toArray();
            $row['amount'] = $withdraw->amount / 100;
            $row['status_name'] = CommissionWithdraw::$statusMap[$withdraw->status] ?? '';
            return $row;
        });

        return $this->paginate($records);
    }

    public function approve(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_commission_withdraw,id',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $withdraw = $this->service->approve(
                CommissionWithdraw::findOrFail($data['id']),
                (int) $request->user()->id,
                $data['reason'] ?? null
            );
        } catch (ApiException $e) {
            return $this->fail([422, $e->getMessage()]);
        }

        return $this->success(['status' => $withdraw->status]);
    }

    public function reject(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_commission_withdraw,id',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $withdraw = $this->service->reject(
                CommissionWithdraw::findOrFail($data['id']),
                (int) $request->user()->id,
                $data['reason'] ?? null
            );
        } catch (ApiException $e) {
            return $this->fail([422, $e->getMessage()]);
        }

        return $this->success(['status' => $withdraw->status]);
    }
}
