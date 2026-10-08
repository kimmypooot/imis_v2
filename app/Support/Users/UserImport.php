<?php

namespace App\Support\Users;

use App\Enums\RoleName;
use App\Http\Requests\User\Concerns\ProfileRules;
use App\Http\Requests\User\Concerns\UsernameRules;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\Usernames;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admin-3, bulk user import: a CSV of staff becomes accounts, in two
 * steps. `preview()` checks every row with the same rules as Add User
 * (plus the office and roles by name, and duplicates within the file)
 * and keeps the result for 30 minutes; `import()` then creates the valid
 * rows through AccountCreator, exactly as Add User would.
 *
 * Deliberately not importable: the office designation (Division Chief /
 * Field Director grants admin access to the office's systems) and the
 * Super Administrator role. Set those one account at a time.
 */
class UserImport
{
    use ProfileRules;
    use UsernameRules;

    public const MAX_ROWS = 500;

    public const COLUMNS = ['honorific', 'first_name', 'middle_name', 'last_name', 'name_extension', 'sex', 'birthday', 'position', 'office', 'email', 'username', 'roles'];

    private const CACHE_MINUTES = 30;

    public function __construct(private readonly AccountCreator $accounts) {}

    /**
     * The template: the header row and one example.
     */
    public static function template(): string
    {
        return implode(',', self::COLUMNS)."\r\n"
            .",Juan,Santos,Dela Cruz,,male,1990-05-14,Administrative Officer II,HRD,juan.delacruz@example.gov.ph,,Supervisor\r\n";
    }

    /**
     * @return array{token: string, rows: array<int, array<string, mixed>>, valid: int, invalid: int}
     *
     * @throws InvalidArgumentException when the file isn't a usable CSV
     */
    public function preview(UploadedFile $file, User $actor): array
    {
        $rows = $this->checked($this->read($file));
        $token = Str::random(32);

        Cache::put($this->cacheKey($actor, $token), $rows, now()->addMinutes(self::CACHE_MINUTES));

        return [
            'token' => $token,
            'rows' => $rows,
            'valid' => collect($rows)->where('errors', [])->count(),
            'invalid' => collect($rows)->where('errors', '!=', [])->count(),
        ];
    }

    /**
     * Creates the valid rows of a preview. Each is checked again (someone
     * may have taken an email or username meanwhile); those that no longer
     * pass are skipped and reported.
     *
     * @return array{created: array<int, array{line: int, name: string, username: string}>, skipped: array<int, array{line: int, errors: array<int, string>}>}|null null when the preview has expired
     */
    public function import(string $token, User $actor, bool $sendPasswordLinks): ?array
    {
        $rows = Cache::pull($this->cacheKey($actor, $token));

        if ($rows === null) {
            return null;
        }

        $created = [];
        $skipped = [];

        foreach (collect($rows)->where('errors', []) as $row) {
            $recheck = $this->checked([$row['input']])[0];

            if ($recheck['errors'] !== []) {
                $skipped[] = ['line' => $row['line'], 'errors' => $recheck['errors']];

                continue;
            }

            ['user' => $user] = $this->accounts->create($recheck['profile'], $recheck['input']['username'] ?: null, $recheck['role_ids'], $sendPasswordLinks, ['source' => 'bulk import']);
            $created[] = ['line' => $row['line'], 'name' => $user->name, 'username' => $user->username];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array<int, array<string, string>> each row keyed by column, with its line number in `_line`
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle, escape: '\\');

        if (! $header) {
            throw new InvalidArgumentException('The file is empty.');
        }

        // Header names in any case and spacing ("First Name" → first_name); a BOM is dropped.
        $header = array_map(fn ($name) => Str::snake(Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)))), $header);
        $missing = array_diff(['first_name', 'last_name', 'email'], $header);

        if ($missing !== []) {
            fclose($handle);
            throw new InvalidArgumentException('The header row needs the columns '.implode(', ', $missing).'. Start from the template.');
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;

            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new InvalidArgumentException('Up to '.self::MAX_ROWS.' people per file. Split it into smaller files.');
            }

            $row = ['_line' => $line];
            foreach (self::COLUMNS as $column) {
                $index = array_search($column, $header, true);
                $row[$column] = $index === false ? '' : trim((string) ($values[$index] ?? ''));
            }
            $rows[] = $row;
        }

        fclose($handle);

        if ($rows === []) {
            throw new InvalidArgumentException('The file has a header row but no people.');
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function checked(array $rows): array
    {
        $offices = OrganizationalUnit::query()->active()->get(['id', 'code', 'short_name', 'name']);
        $roles = Role::query()->active()->where('name', '!=', RoleName::SuperAdmin->value)->get(['id', 'name', 'display_name']);
        $emails = collect($rows)->countBy(fn ($row) => Str::lower($row['email']));
        $usernames = collect($rows)->filter(fn ($row) => $row['username'] !== '')->countBy(fn ($row) => Usernames::normalize($row['username']));

        return array_map(function (array $row) use ($offices, $roles, $emails, $usernames) {
            $errors = [];

            $office = $this->office($row['office'], $offices);
            if ($row['office'] !== '' && $office === null) {
                $errors[] = "Office \"{$row['office']}\" not found (use its code, short name or name).";
            }

            [$roleIds, $unknown] = $this->roles($row['roles'], $roles);
            if ($unknown !== []) {
                $errors[] = 'Role(s) not found or not allowed here: '.implode(', ', $unknown).'.';
            }

            // "atty" → "Atty."; a title typed into the first name moves out of it.
            [$titleInName, $firstName] = User::splitHonorific($row['first_name']);
            $honorific = $row['honorific'] === ''
                ? $titleInName
                : (collect(User::HONORIFICS)->first(fn ($title) => strcasecmp(rtrim($title, '.'), rtrim($row['honorific'], '.')) === 0) ?? $row['honorific']);

            $profile = [
                'honorific' => $honorific,
                'first_name' => $firstName,
                'middle_name' => $row['middle_name'] ?: null,
                'last_name' => $row['last_name'],
                'name_extension' => $row['name_extension'] ?: null,
                'sex' => Str::lower($row['sex']) ?: null,
                'birthday' => $row['birthday'] ?: null,
                'position' => $row['position'] ?: null,
                'organizational_unit_id' => $office?->id,
                'email' => Str::lower($row['email']),
            ];

            $rules = $this->profileRules();
            unset($rules['office_designation'], $rules['organizational_unit_id']);
            $validator = Validator::make([...$profile, 'username' => $row['username'] ?: null], [
                ...$rules,
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'username' => ['nullable', ...$this->usernameRules()],
            ]);
            $errors = [...$errors, ...$validator->errors()->all()];

            if (($emails[$profile['email']] ?? 0) > 1) {
                $errors[] = 'This email appears more than once in the file.';
            }
            if ($row['username'] !== '' && ($usernames[Usernames::normalize($row['username'])] ?? 0) > 1) {
                $errors[] = 'This username appears more than once in the file.';
            }

            return [
                'line' => $row['_line'],
                'input' => $row,
                'name' => User::composeName($profile['first_name'], null, $row['last_name'], null, $profile['honorific']),
                'email' => $profile['email'],
                'office' => $office ? ($office->short_name ?: $office->name) : null,
                'roles' => $roles->whereIn('id', $roleIds)->pluck('display_name')->values()->all(),
                'username' => $row['username'] !== ''
                    ? Usernames::normalize($row['username'])
                    : ($profile['first_name'] !== '' && $row['last_name'] !== '' ? Usernames::generateFromParts($profile['first_name'], $profile['middle_name'], $row['last_name']) : null),
                'username_generated' => $row['username'] === '',
                'profile' => $profile,
                'role_ids' => $roleIds,
                'errors' => array_values(array_unique($errors)),
            ];
        }, $rows);
    }

    /**
     * @param  Collection<int, OrganizationalUnit>  $offices
     */
    private function office(string $value, Collection $offices): ?OrganizationalUnit
    {
        if ($value === '') {
            return null;
        }

        $value = Str::lower($value);

        return $offices->first(fn (OrganizationalUnit $office) => in_array($value, array_map(fn ($name) => Str::lower((string) $name), [$office->code, $office->short_name, $office->name]), true));
    }

    /**
     * Role names or display names, separated by ";" or ",".
     *
     * @param  Collection<int, Role>  $roles
     * @return array{0: array<int, int>, 1: array<int, string>} ids found, names not found
     */
    private function roles(string $value, Collection $roles): array
    {
        $ids = [];
        $unknown = [];

        foreach (array_filter(array_map('trim', preg_split('/[;,]/', $value))) as $name) {
            $role = $roles->first(fn (Role $role) => Str::lower($role->name) === Str::lower($name) || Str::lower($role->display_name) === Str::lower($name));
            $role ? $ids[] = $role->id : $unknown[] = $name;
        }

        return [array_values(array_unique($ids)), $unknown];
    }

    private function cacheKey(User $actor, string $token): string
    {
        return "user-import:{$actor->id}:{$token}";
    }
}
