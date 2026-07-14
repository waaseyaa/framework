<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

#[CoversClass(Fts5SearchIndexer::class)]
#[CoversClass(Fts5SearchProvider::class)]
final class Fts5OrthographyTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;
    private Fts5SearchProvider $provider;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->provider = new Fts5SearchProvider($this->database, $this->indexer);
    }

    #[Test]
    public function schema_declares_the_orthography_preserving_tokenizer(): void
    {
        $this->index('schema-probe', 'Aaniin');
        $rows = iterator_to_array($this->database->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'search_index'",
        ));

        self::assertStringContainsString(
            "tokenize=\"unicode61 remove_diacritics 0 tokenchars '''’ʼ'\"",
            (string) $rows[0]['sql'],
        );
        self::assertStringNotContainsString('porter', (string) $rows[0]['sql']);
    }

    #[Test]
    public function indigenous_orthographies_round_trip_without_english_stemming_or_diacritic_folding(): void
    {
        $this->index('double-vowel', 'Aaniin kina gwaya');
        $this->index('curly-glottal', 'diba’igan');
        $this->index('curly-separated', 'diba igan');
        $this->index('ascii-apostrophe', "anishinaabemowin's");
        $this->index('ascii-separated', 'anishinaabemowin s');
        $this->index('modifier-glottal', 'Anishinaabeʼ');
        $this->index('macron', 'nīna');
        $this->index('plain-vowel', 'nina');
        $this->index('acute', 'anishinábemowin');
        $this->index('acute-plain', 'anishinabemowin');
        $this->index('syllabics', 'ᐊᓂᔑᓈᐯ');
        $this->index('english-variants', 'tests testing');

        self::assertSame(['double-vowel'], $this->idsFor('Aaniin'), 'Double vowels must remain searchable as written.');
        self::assertSame(['curly-glottal'], $this->idsFor('diba’igan'), 'The apostrophe/glottal form must remain one distinct token.');
        self::assertSame(['ascii-apostrophe'], $this->idsFor("anishinaabemowin's"), 'ASCII apostrophes must remain internal to the word.');
        self::assertSame(['modifier-glottal'], $this->idsFor('Anishinaabeʼ'), 'U+02BC must survive indexing and querying.');
        self::assertSame(['macron'], $this->idsFor('nīna'), 'Macrons must not fold into an unmarked vowel.');
        self::assertSame(['plain-vowel'], $this->idsFor('nina'), 'Unmarked vowels must remain distinct from macrons.');
        self::assertSame(['acute'], $this->idsFor('anishinábemowin'), 'Diacritics must not fold into an unmarked vowel.');
        self::assertSame(['syllabics'], $this->idsFor('ᐊᓂᔑᓈᐯ'), 'Canadian syllabics must round trip where SQLite unicode61 supports them.');
        self::assertSame([], $this->idsFor('test'), 'An English Porter stem must not manufacture a match for tests/testing.');
    }

    /** @return list<string> */
    private function idsFor(string $query): array
    {
        return array_map(
            static fn($hit): string => $hit->id,
            $this->provider->search(new SearchRequest($query, includeFacets: false))->hits,
        );
    }

    private function index(string $id, string $body): void
    {
        $this->indexer->index(new class ($id, $body) implements SearchIndexableInterface {
            public function __construct(private readonly string $id, private readonly string $body) {}
            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return ['title' => $this->body, 'body' => $this->body]; }
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => 'node',
                    'created_at' => '2026-07-14T00:00:00Z',
                ];
            }
        });
    }
}
