<?php

namespace App\Filament\Resources\Assignments;

use App\Filament\Resources\Assignments\Pages\CreateAssignment;
use App\Filament\Resources\Assignments\Pages\EditAssignment;
use App\Filament\Resources\Assignments\Pages\ListAssignments;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\News;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssignmentResource extends Resource
{
    protected static ?string $model = Assignment::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;
    protected static string|\UnitEnum|null $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'Assignments';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool { return auth()->user()?->isAuthor() ?? false; }
    public static function canCreate(): bool  { return auth()->user()?->isEditor() ?? false; }
    public static function canEdit($r): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        if ($u->isEditor()) return true;
        // Authors can update their own assignments to claim / progress
        // / submit them, but not change creator/category/etc — the
        // form sections below disable those fields for them.
        return $r && (int) $r->assigned_to_user_id === (int) $u->id;
    }
    public static function canDelete($r): bool { return auth()->user()?->isEditor() ?? false; }

    public static function form(Schema $schema): Schema
    {
        $isEditor = auth()->user()?->isEditor() ?? false;

        return $schema->components([
            Section::make('Pitch')->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(255)
                    ->disabled(! $isEditor)
                    ->columnSpanFull(),
                Textarea::make('brief')->rows(5)->maxLength(20000)
                    ->disabled(! $isEditor)
                    ->columnSpanFull()
                    ->helperText('Background, angle, sources to talk to, anything the author needs to know.'),
                Select::make('status')
                    ->options(collect(Assignment::STATUSES)->mapWithKeys(fn ($s) => [$s => ucwords(str_replace('_', ' ', $s))]))
                    ->required(),
                Select::make('priority')
                    ->options(collect(Assignment::PRIORITIES)->mapWithKeys(fn ($p) => [$p => ucfirst($p)]))
                    ->default('normal'),
                DateTimePicker::make('deadline')
                    ->seconds(false)
                    ->disabled(! $isEditor)
                    ->helperText('When the editor wants this filed.'),
                Select::make('category_id')
                    ->label('Category')
                    ->disabled(! $isEditor)
                    ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
            ]),

            Section::make('People')->columns(2)->schema([
                Select::make('assigned_to_user_id')
                    ->label('Assigned to')
                    ->disabled(! $isEditor)
                    ->options(fn () => User::query()
                        ->whereIn('role', ['admin', 'sub_admin', 'editor', 'author'])
                        ->where('is_agent', false)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('— unassigned —'),
                Select::make('created_by_user_id')
                    ->label('Created by')
                    ->disabled()
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
            ]),

            Section::make('Linked article')->schema([
                Select::make('news_id')
                    ->label('Article')
                    ->options(fn () => News::orderByDesc('id')->limit(500)->pluck('title', 'id'))
                    ->searchable()
                    ->placeholder('— not yet linked —')
                    ->helperText('Tie this assignment to the article that fulfilled it. Filled in once the author files.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(60)->wrap(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'published'   => 'success',
                    'submitted'   => 'info',
                    'in_progress' => 'warning',
                    'assigned'    => 'primary',
                    'archived'    => 'gray',
                    default       => 'gray',
                }),
                TextColumn::make('priority')->badge()
                    ->color(fn ($state) => match ($state) {
                        'urgent' => 'danger',
                        'high'   => 'warning',
                        default  => 'gray',
                    }),
                TextColumn::make('assignee.name')->label('Assignee')->toggleable(),
                TextColumn::make('category.name')->label('Category')->toggleable(),
                TextColumn::make('deadline')->dateTime('M j')->sortable()->toggleable(),
                TextColumn::make('article.title')->label('Article')->limit(40)->toggleable()->wrap(),
                TextColumn::make('created_at')->dateTime('M j')->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(
                    collect(Assignment::STATUSES)->mapWithKeys(fn ($s) => [$s => ucwords(str_replace('_', ' ', $s))])
                ),
                SelectFilter::make('priority')->options(
                    collect(Assignment::PRIORITIES)->mapWithKeys(fn ($p) => [$p => ucfirst($p)])
                ),
                SelectFilter::make('assigned_to_user_id')
                    ->label('Assignee')
                    ->relationship('assignee', 'name'),
            ]);
    }

    /**
     * Authors only see assignments tied to them. Editors+ see the
     * whole queue. Mirrors the NewsResource scoping.
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();
        $u = auth()->user();
        if ($u && ! $u->isEditor()) {
            $q->where('assigned_to_user_id', $u->id);
        }
        return $q;
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAssignments::route('/'),
            'create' => CreateAssignment::route('/create'),
            'edit'   => EditAssignment::route('/{record}/edit'),
        ];
    }
}
