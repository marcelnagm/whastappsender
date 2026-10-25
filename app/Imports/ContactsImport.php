<?php

namespace App\Imports;

use App\Models\Contact;
use Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactsImport implements ToModel , WithHeadingRow
{
    /** Rows left out because the plan's contact limit was reached */
    public int $skipped = 0;

    /** Contacts the plan still allows (null = unlimited); read once, on the first valid row */
    private ?int $room = null;
    private bool $roomLoaded = false;

    /**
     * Maps spreadsheet rows to Contact models.
     * Supports multiple heading aliases (Portuguese/English) for legacy files.
     */
    public function model(array $row)
    {
        // dd($row);
        $data = [
            'user_id' => Auth::user()->id,
        ];
        if (key_exists('name' , $row) && !isset($data['name']))
            $data['name'] = $row['name'];
        if (key_exists('nome' , $row) && !isset($data['name']))
            $data['name'] = $row['nome'];
        if (key_exists('contact' , $row) && !isset($data['contact']))
            $data['contact'] = $row['contact'];
        if (key_exists('contato' , $row) && !isset($data['contact']))
            $data['contact'] = $row['contato'];
        if (key_exists('telefone' , $row) && !isset($data['contact']))
            $data['contact'] = $row['telefone'];
        if (key_exists('celular' , $row) && !isset($data['contact']))
            $data['contact'] = $row['celular'];
        if (key_exists('email' , $row) && !isset($data['email']))
            $data['email'] = $row['email'];
           
        if (key_exists('e-mail' , $row))
            $data['email'] = $row['e-mail'];
        if (!isset($data['contact']) || !isset($data['name']))
            return null;

        if (!$this->roomLoaded) {
            $this->room = app(\App\Services\Billing\Entitlements::class)->remaining(Auth::user(), 'contacts');
            $this->roomLoaded = true;
        }
        if ($this->room !== null) {
            if ($this->room <= 0) {
                $this->skipped++;

                return null;
            }
            $this->room--;
        }

        return new Contact($data);
    }
}
