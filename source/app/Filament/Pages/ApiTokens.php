<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApiTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'API';

    protected static ?string $title = 'API tokens';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.api-tokens';

    public ?array $data = ['name' => '', 'abilities' => ['read']];

    public ?string $newlyCreatedToken = null;

    /**
     * Source of truth for which abilities each role may put on a token.
     * Anything outside this list is rejected on the server side regardless
     * of what the form payload contains.
     */
    private const ROLE_ABILITIES = [
        'admin' => ['read', 'write', 'delete'],
        'sub_admin' => ['read', 'write', 'delete'],
        'editor' => ['read', 'write', 'delete'],
        'author' => ['read', 'write'],
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->helperText('Shown to identify this token. E.g., "Staging bot", "Zapier integration".'),
            CheckboxList::make('abilities')
                ->options($this->availableAbilityOptions())
                ->default(['read'])
                ->columns(1)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => auth()->user()->tokens()->getQuery())
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('abilities')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : (string) $state)
                    ->badge()
                    ->separator(','),
                TextColumn::make('last_used_at')->dateTime()->sortable()->label('Last used'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make()->label('Revoke'),
            ])
            ->emptyStateHeading('No tokens yet')
            ->emptyStateDescription('Create your first API token using the form above.');
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            Notification::make()->title('Name is required.')->danger()->send();
            return;
        }

        $requested = is_array($data['abilities'] ?? null) ? $data['abilities'] : ['read'];
        $allowed = $this->allowedAbilitiesForCurrentUser();

        // Reject the wildcard explicitly. A panel-level user should never
        // mint a "*" token even if they trickled past the form options.
        if (in_array('*', $requested, true)) {
            Notification::make()
                ->title('Wildcard tokens are not allowed.')
                ->danger()
                ->send();
            return;
        }

        $abilities = array_values(array_intersect($requested, $allowed));
        if (empty($abilities)) {
            $abilities = ['read'];
        }

        $token = auth()->user()->createToken($name, $abilities);

        $this->newlyCreatedToken = $token->plainTextToken;
        $this->form->fill(['name' => '', 'abilities' => ['read']]);

        Notification::make()
            ->title('API token created')
            ->body('Copy it now — it will not be shown again.')
            ->success()
            ->send();
    }

    public function dismissToken(): void
    {
        $this->newlyCreatedToken = null;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function allowedAbilitiesForCurrentUser(): array
    {
        $role = auth()->user()->role ?? null;
        return self::ROLE_ABILITIES[$role] ?? [];
    }

    private function availableAbilityOptions(): array
    {
        $allowed = $this->allowedAbilitiesForCurrentUser();
        $labels = [
            'read' => 'Read articles, categories, tags',
            'write' => 'Create and update articles',
            'delete' => 'Delete articles',
        ];

        return array_intersect_key($labels, array_flip($allowed));
    }
}
