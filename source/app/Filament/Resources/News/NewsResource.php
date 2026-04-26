<?php

namespace App\Filament\Resources\News;

use App\Filament\Resources\News\Pages\CreateNews;
use App\Filament\Resources\News\Pages\EditNews;
use App\Filament\Resources\News\Pages\ListNews;
use App\Filament\Resources\News\Schemas\NewsForm;
use App\Filament\Resources\News\Tables\NewsTable;
use App\Models\News;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $navigationLabel = 'Articles';

    protected static ?string $modelLabel = 'Article';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return NewsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NewsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNews::route('/'),
            'create' => CreateNews::route('/create'),
            'edit' => EditNews::route('/{record}/edit'),
            'analytics' => \App\Filament\Resources\News\Pages\ArticleAnalyticsPage::route('/{record}/analytics'),
        ];
    }

    /* ---------- Authorization ----------
     *
     * Filament defaults are permissive: any panel user can list/edit/delete
     * every record unless the Resource explicitly says otherwise. The roles
     * in this app form a hierarchy admin > sub_admin > editor > author. We
     * gate the article surface as:
     *   - Authors see and modify only their own articles (primary byline).
     *   - Authors may not delete published articles — only their own drafts.
     *   - Editors+ can manage the whole catalog.
     */

    protected static function isOwn(?News $record): bool
    {
        $u = auth()->user();
        return $u && $record && (int) $record->user_id === (int) $u->id;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAuthor() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isAuthor() ?? false;
    }

    public static function canView($record): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        return $u->isEditor() || self::isOwn($record);
    }

    public static function canEdit($record): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        return $u->isEditor() || self::isOwn($record);
    }

    public static function canDelete($record): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        if ($u->isEditor()) return true;
        // Authors can only delete their own drafts.
        return self::isOwn($record)
            && in_array($record->editorial_status, [News::STATUS_DRAFT, News::STATUS_IN_REVIEW], true);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    /**
     * Hide other authors' articles from the list view for non-editors.
     * Editors+ retain the full catalog. Filtering at the query level
     * also ensures search/filter widgets can't surface restricted rows.
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();
        $u = auth()->user();
        if ($u && ! $u->isEditor()) {
            $q->where('user_id', $u->id);
        }
        return $q;
    }
}
