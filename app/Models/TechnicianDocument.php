<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianDocument extends Model
{
    protected $fillable = [
        'technician_id',
        'document_type',
        'file_path',
        'file_name',
        'verified',
        'verified_by',
        'verified_at',
        'expiry_date',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    const TYPE_TECHNICAL_CERT = 'technical_cert';
    const TYPE_NCA_LICENSE = 'nca_license';
    const TYPE_TERTIARY_CERT = 'tertiary_cert';
    const TYPE_ID_CARD = 'id_card';
    const TYPE_PASSPORT_PHOTO = 'passport_photo';
    const TYPE_PIN_CERT = 'pin_cert';
    const TYPE_VETTING_FORM = 'vetting_form';
    const TYPE_OTHER = 'other';

    public static function documentTypes(): array
    {
        return [
            self::TYPE_TECHNICAL_CERT => 'Technical Certificate',
            self::TYPE_NCA_LICENSE => 'NCA License',
            self::TYPE_TERTIARY_CERT => 'Tertiary Certificate',
            self::TYPE_ID_CARD => 'ID Card',
            self::TYPE_PASSPORT_PHOTO => 'Passport Photo',
            self::TYPE_PIN_CERT => 'PIN Certificate',
            self::TYPE_VETTING_FORM => 'Vetting/Induction Form',
            self::TYPE_OTHER => 'Other',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
