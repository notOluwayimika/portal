<?php

namespace App\Exports;

use App\Models\Guardian;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class GuardiansExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(protected Request $request) {}

    public function query()
    {
        return Guardian::query()
            ->leftJoin('users', 'users.id', '=', 'guardians.user_id')
            ->select('guardians.*')
            ->withCount('students')
            ->when($this->request->search, function ($q) {
                $term = '%'.$this->request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('guardians.first_name', 'LIKE', $term)
                        ->orWhere('guardians.last_name', 'LIKE', $term)
                        ->orWhere('guardians.phone', 'LIKE', $term)
                        ->orWhere('users.email', 'LIKE', $term);
                });
            })
            ->when($this->request->status, fn ($q) => $q->where('guardians.status', $this->request->status))
            ->with('user')
            ->latest('guardians.created_at');
    }

    public function headings(): array
    {
        return [
            'Full Name',
            'Phone',
            'WhatsApp',
            'Email',
            'Status',
            'Has Login',
            'Children Count',
            'Created At',
        ];
    }

    public function map($guardian): array
    {
        $user = $guardian->user;
        // `disabled_at` stays HERE. This asks "has an active login", which is
        // deliverability AND enabled — two questions. hasDeliverableEmail() owns only
        // the first; folding the second into it would change who receives bulk mail.
        $hasLogin = $user && $user->disabled_at === null && $user->hasDeliverableEmail();

        return [
            $guardian->full_name,
            $guardian->phone ?? '',
            $guardian->whatsapp_number ?? '',
            $user?->hasDeliverableEmail() ? $user->email : '',
            $guardian->status ?? '',
            $hasLogin ? 'Yes' : 'No',
            $guardian->students_count ?? 0,
            $guardian->created_at?->format('Y-m-d') ?? '',
        ];
    }
}
