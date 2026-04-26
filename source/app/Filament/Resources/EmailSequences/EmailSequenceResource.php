<?php

namespace App\Filament\Resources\EmailSequences;

use App\Filament\Resources\EmailSequences\Pages\CreateEmailSequence;
use App\Filament\Resources\EmailSequences\Pages\EditEmailSequence;
use App\Filament\Resources\EmailSequences\Pages\ListEmailSequences;
use App\Filament\Resources\EmailSequences\RelationManagers\StepsRelationManager;
use App\Models\EmailSequence;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmailSequenceResource extends Resource
{
    protected static ?string $model = EmailSequence::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;
    protected static string|\UnitEnum|null $navigationGroup = 'Newsletter';
    protected static ?string $navigationLabel = 'Drip campaigns';
    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canCreate(): bool  { return auth()->user()?->isAdmin() ?? false; }
    public static function canEdit($r): bool  { return auth()->user()?->isAdmin() ?? false; }
    public static function canDelete($r): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('slug')->required()->maxLength(80)
                        ->helperText('Stable identifier; only set during creation. Steps reference this.'),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Select::make('trigger_event')
                        ->options([
                            EmailSequence::TRIGGER_SUBSCRIBER_CONFIRMED => 'Newsletter subscriber confirmed',
                            EmailSequence::TRIGGER_USER_SIGNED_UP       => 'User signed up',
                            EmailSequence::TRIGGER_MANUAL               => 'Manual (admin-driven only)',
                        ])
                        ->required()
                        ->default(EmailSequence::TRIGGER_MANUAL)
                        ->helperText('When this fires, every active sequence with a matching trigger spawns a run for the recipient.'),
                    Toggle::make('active')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->color('gray')->toggleable(),
                TextColumn::make('trigger_event')->badge()->toggleable()
                    ->formatStateUsing(fn ($s) => match ($s) {
                        'subscriber_confirmed' => 'Subscriber confirmed',
                        'user_signed_up'       => 'User signed up',
                        default                => 'Manual',
                    }),
                IconColumn::make('active')->boolean()->toggleable(),
                TextColumn::make('steps_count')->counts('steps')->label('Steps')->numeric()->toggleable(),
                TextColumn::make('runs_count')->counts(['runs as runs_total'])->label('Total runs')->numeric()->toggleable(),
                TextColumn::make('active_runs')
                    ->label('Active runs')
                    ->state(fn (EmailSequence $r) => $r->runs()->whereNull('completed_at')->whereNull('paused_at')->count())
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('updated_at')->dateTime('M j, Y g:ia')->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [StepsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListEmailSequences::route('/'),
            'create' => CreateEmailSequence::route('/create'),
            'edit'   => EditEmailSequence::route('/{record}/edit'),
        ];
    }
}
