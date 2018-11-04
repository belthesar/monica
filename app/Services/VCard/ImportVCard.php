<?php

namespace App\Services\VCard;

use App\Models\User\User;
use Sabre\VObject\Reader;
use App\Helpers\DateHelper;
use App\Helpers\VCardHelper;
use App\Helpers\LocaleHelper;
use App\Services\BaseService;
use App\Models\Contact\Gender;
use App\Models\Contact\Address;
use App\Models\Contact\Contact;
use Illuminate\Validation\Rule;
use App\Helpers\CountriesHelper;
use Sabre\VObject\Component\VCard;
use App\Models\Contact\ContactField;
use App\Models\Contact\ContactFieldType;

class ImportVCard extends BaseService
{
    public const BEHAVIOUR_ADD = 'behaviour_add';
    public const BEHAVIOUR_REPLACE = 'behaviour_replace';

    public const ERROR_CONTACT_EXIST = -1;
    public const ERROR_CONTACT_DOESNT_HAVE_FIRSTNAME = -2;

    /**
     * Valids value for frequency type.
     *
     * @var array
     */
    public static $behaviourTypes = [
        self::BEHAVIOUR_ADD, self::BEHAVIOUR_REPLACE,
    ];

    /**
     * The contact fields ids.
     *
     * @var array
     */
    protected $contactFields;

    /**
     * The Account id.
     *
     * @var int
     */
    protected $accountId;

    /**
     * The "Vcard" gender that will be associated with all imported contacts.
     *
     * @var array[Gender]
     */
    protected $genders;

    /**
     * Create a new command.
     *
     * @param int accountId
     */
    public function __construct($accountId)
    {
        $this->accountId = $accountId;
        //parent::__construct();
    }

    /**
     * Get the validation rules that apply to the service.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'account_id' => 'required|integer|exists:accounts,id',
            'contact_id' => 'nullable|integer',
            'user_id' => 'required|integer',
            'entry' => 'required|string',
            'behaviour' => [
                'required',
                Rule::in(self::$behaviourTypes),
            ],
        ];
    }

    /**
     * Import one VCard.
     *
     * @param array $data
     * @return int
     */
    public function execute(array $data) : int
    {
        $this->validate(
            array_except($data, ['account_id'])
            + ['account_id' => $this->accountId]
        );

        if ($data['contact_id']) {
            Contact::where('account_id', $this->accountId)
                ->findOrFail($data['contact_id']);
        }

        User::where('account_id', $this->accountId)
            ->findOrFail($data['user_id']);

        return $this->process($data);
    }

    /**
     * Import one VCard.
     *
     * @param array $data
     * @return int
     */
    public function process(array $data) : int
    {
        $behaviour = $data['behaviour'] ?: self::BEHAVIOUR_ADD;

        $entry = Reader::read($data['entry']);

        if (! $this->canImportCurrentEntry($entry)) {
            return self::ERROR_CONTACT_DOESNT_HAVE_FIRSTNAME;
        }

        $contact = $this->existingContact($entry, $data['contact_id']);
        if ($contact && $behaviour === self::BEHAVIOUR_ADD) {
            return self::ERROR_CONTACT_EXIST;
        }

        $contact = $this->createContactFromCurrentEntry($contact, $entry);

        return $contact->id;
    }

    /**
     * Get or create the gender called "Vcard" that is associated with all
     * imported contacts.
     *
     * @param  string  $genderCode
     * @return Gender
     */
    private function getGender($genderCode) : Gender
    {
        if (! array_has($this->genders, $genderCode)) {
            switch ($genderCode) {
                case 'M':
                    $gender = $this->getGenderOfName('Man') ?? $this->getGenderOfName('vCard');
                    break;
                case 'F':
                    $gender = $this->getGenderOfName('Woman') ?? $this->getGenderOfName('vCard');
                    break;
                default:
                    $gender = $this->getGenderOfName('vCard');
                    break;
            }

            if (! $gender) {
                $gender = new Gender;
                $gender->account_id = $this->accountId;
                $gender->name = 'vCard';
                $gender->save();
            }

            array_set($this->genders, $genderCode, $gender);
        }

        return array_get($this->genders, $genderCode);
    }

    /**
     * Get the gender by name.
     *
     * @param  string  $name
     * @return Gender|null
     */
    private function getGenderOfName($name)
    {
        return Gender::where([
            ['account_id', $this->accountId],
            ['name', $name],
        ])->first();
    }

    /**
     * Check whether a contact has a first name or a nickname. If not, contact
     * can not be imported.
     *
     * @param VCard $entry
     * @return bool
     */
    private function canImportCurrentEntry(VCard $entry) : bool
    {
        return
            $this->hasFirstnameInN($entry) ||
            $this->hasNickname($entry) ||
            $this->hasFN($entry);
    }

    /**
     * @param  VCard $entry
     * @return bool
     */
    private function hasFirstnameInN(VCard $entry) : bool
    {
        return $entry->N !== null && ! empty($entry->N->getParts()[1]);
    }

    /**
     * @param  VCard $entry
     * @return bool
     */
    private function hasNICKNAME(VCard $entry) : bool
    {
        return ! empty((string) $entry->NICKNAME);
    }

    /**
     * @param  VCard $entry
     * @return bool
     */
    private function hasFN(VCard $entry): bool
    {
        return ! empty((string) $entry->FN);
    }

    /**
     * Check whether the email is valid.
     *
     * @param string $email
     * @return bool
     */
    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Check whether the contact already exists in the database.
     *
     * @param  VCard $entry
     * @param  int $contact_id
     * @return Contact|null
     */
    private function existingContact(VCard $entry, $contact_id = null)
    {
        $contact = null;
        if (! is_null($contact_id)) {
            $contact = Contact::where('account_id', $this->accountId)
                ->find($contact_id);
        }

        if (! $contact) {
            $contact = $this->existingContactWithEmail($entry);
        }

        if (! $contact) {
            $contact = $this->existingContactWithName($entry);
        }

        return $contact;
    }

    /**
     * Search with email field.
     *
     * @param  VCard $entry
     * @return Contact|null
     */
    private function existingContactWithEmail(VCard $entry)
    {
        if (empty($entry->EMAIL)) {
            return;
        }

        $email = (string) $entry->EMAIL;

        if ($this->isValidEmail($email)) {
            $contactField = ContactField::where([
                ['account_id', $this->accountId],
                ['contact_field_type_id', $this->contactFieldTypeId('email')],
            ])->whereIn('data', iterator_to_array($email))->first();

            if ($contactField) {
                return $contactField->contact;
            }
        }
    }

    /**
     * Search with names fields.
     *
     * @param  VCard $entry
     * @return Contact|null
     */
    private function existingContactWithName(VCard $entry)
    {
        $contact = new Contact;
        $this->importNames($contact, $entry);

        return Contact::where([
            ['account_id', $this->accountId],
            ['first_name', $contact->first_name],
            ['middle_name', $contact->middle_name],
            ['last_name', $contact->last_name],
        ])->first();
    }

    /**
     * Formats and returns a string for the contact.
     *
     * @param null|string $value
     * @return null|string
     */
    private function formatValue($value): string
    {
        return ! empty((string) $value) ? str_replace('\;', ';', trim((string) $value)) : (string) null;
    }

    /**
     * Create the Contact object matching the current entry.
     *
     * @param  Contact $contact
     * @param  VCard $entry
     * @return Contact
     */
    private function createContactFromCurrentEntry(Contact $contact, VCard $entry): Contact
    {
        if (! $contact) {
            $contact = new Contact;
            $contact->account_id = $this->accountId;
            $contact->setAvatarColor();
        }

        $this->importNames($contact, $entry);
        $this->importGender($contact, $entry);
        $this->importWorkInformation($contact, $entry);
        $this->importBirthday($contact, $entry);
        $this->importAddress($contact, $entry);
        $this->importEmail($contact, $entry);
        $this->importTel($contact, $entry);

        $contact->save();

        return $contact;
    }

    /**
     * Import names of the contact.
     *
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importNames(Contact $contact, VCard $entry): void
    {
        if ($this->hasFirstnameInN($entry)) {
            $this->importFromN($contact, $entry);
        } elseif ($this->hasFN($entry)) {
            $this->importFromFN($contact, $entry);
        } elseif ($this->hasNICKNAME($entry)) {
            $this->importFromNICKNAME($contact, $entry);
        } else {
            throw new \LogicException('Check if you can import entry!');
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importFromN(Contact $contact, VCard $entry): void
    {
        $contact->last_name = $this->formatValue($entry->N->getParts()[0]);
        $contact->first_name = $this->formatValue($entry->N->getParts()[1]);
        $contact->middle_name = $this->formatValue($entry->N->getParts()[2]);
        //$contact->prefix = $this->formatValue($entry->N->getParts()[3]);
        //$contact->suffix = $this->formatValue($entry->N->getParts()[4]);

        if (! empty($entry->NICKNAME)) {
            $contact->nickname = $this->formatValue($entry->NICKNAME);
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importFromNICKNAME(Contact $contact, VCard $entry): void
    {
        $contact->first_name = $this->formatValue($entry->NICKNAME);
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importFromFN(Contact $contact, VCard $entry): void
    {
        $fullnameParts = preg_split('/ +/', $entry->FN);
        $contact->first_name = $this->formatValue($fullnameParts[0]);
        $contact->last_name = $this->formatValue($fullnameParts[1]);

        if (! empty($entry->NICKNAME)) {
            $contact->nickname = $this->formatValue($entry->NICKNAME);
        }
    }

    /**
     * Import gender of the contact.
     *
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importGender(Contact $contact, VCard $entry): void
    {
        if ($entry->GENDER) {
            $contact->gender_id = $this->getGender($entry->GENDER)->id;
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importWorkInformation(Contact $contact, VCard $entry): void
    {
        if ($entry->ORG) {
            $contact->company = $this->formatValue($entry->ORG);
        }

        if ($entry->ROLE) {
            $contact->job = $this->formatValue($entry->ROLE);
        }

        if ($entry->TITLE) {
            $contact->job = $this->formatValue($entry->TITLE);
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importBirthday(Contact $contact, VCard $entry): void
    {
        if ($entry->BDAY && ! empty((string) $entry->BDAY)) {
            $birthdate = DateHelper::parseDate((string) $entry->BDAY);
            if (! is_null($birthdate)) {
                $specialDate = $contact->setSpecialDate('birthdate', $birthdate->format('Y'), $birthdate->format('m'), $birthdate->format('d'));
                $specialDate->setReminder('year', 1, trans('people.people_add_birthday_reminder', ['name' => $contact->first_name]));
            }
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importAddress(Contact $contact, VCard $entry): void
    {
        if (! $entry->ADR) {
            return;
        }

        foreach ($entry->ADR as $adr) {
            Address::firstOrCreate([
                'account_id' => $contact->account_id,
                'contact_id' => $contact->id,
                'street' => $this->formatValue($adr->getParts()[2]),
                'city' => $this->formatValue($adr->getParts()[3]),
                'province' => $this->formatValue($adr->getParts()[4]),
                'postal_code' => $this->formatValue($adr->getParts()[5]),
                'country' => CountriesHelper::find($adr->getParts()[6]),
            ]);
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importEmail(Contact $contact, VCard $entry): void
    {
        if (is_null($entry->EMAIL)) {
            return;
        }

        foreach ($entry->EMAIL as $email) {
            if ($this->isValidEmail($email)) {
                ContactField::firstOrCreate([
                    'account_id' => $contact->account_id,
                    'contact_id' => $contact->id,
                    'data' => $this->formatValue($email),
                    'contact_field_type_id' => $this->contactFieldTypeId('email'),
                ]);
            }
        }
    }

    /**
     * @param Contact $contact
     * @param  VCard $entry
     * @return void
     */
    private function importTel(Contact $contact, VCard $entry): void
    {
        if (is_null($entry->TEL)) {
            return;
        }

        foreach ($entry->TEL as $tel) {
            $tel = (string) $entry->TEL;

            $countryISO = VCardHelper::getCountryISOFromSabreVCard($entry);

            $tel = LocaleHelper::formatTelephoneNumberByISO($tel, $countryISO);

            ContactField::firstOrCreate([
                'account_id' => $contact->account_id,
                'contact_id' => $contact->id,
                'data' => $this->formatValue($tel),
                'contact_field_type_id' => $this->contactFieldTypeId('phone'),
            ]);
        }
    }

    /**
     * Get the contact field type id for the $type.
     *
     * @param string $type  The type of the ContactFieldType, or the name
     * @return int
     */
    private function contactFieldTypeId(string $type): int
    {
        if (! array_has($this->contactFields, $type)) {
            $contactFieldType = ContactFieldType::where([
                ['account_id', $this->accountId],
                ['type', $type],
            ])->first();
            if (is_null($contactFieldType)) {
                $contactFieldType = ContactFieldType::where([
                    ['account_id', $this->accountId],
                    ['name', $type],
                ])->first();
            }
            array_set($this->contactFields, $type, $contactFieldType != null ? $contactFieldType->id : null);
        }

        return array_get($this->contactFields, $type);
    }
}
