<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/csv-import.php';

final class CsvImportTest extends TestCase
{
    public function testLetterboxdWatchedKeepsQuotedTitlesAndYears(): void
    {
        $csv = "Date,Name,Year,Letterboxd URI\n"
            . "2024-01-02,\"Lord of the Rings: The Fellowship of the Ring, Extended\",2001,https://boxd.it/1\n"
            . "2024-01-03,Parasite,2019,https://boxd.it/2\n";

        $rows = csvImportParse($csv, 'letterboxd', 'watched');

        $this->assertCount(2, $rows);
        $this->assertSame('Lord of the Rings: The Fellowship of the Ring, Extended', $rows[0]['title']);
        $this->assertSame(2001, $rows[0]['year']);
        $this->assertSame('movie', $rows[0]['kind']);
        $this->assertSame('watched', $rows[0]['status']);
        $this->assertNull($rows[0]['rating']);
    }

    public function testLetterboxdRatingsAreConvertedFromHalfStarsToTen(): void
    {
        $csv = "Date,Name,Year,Letterboxd URI,Rating\n"
            . "2024-01-02,Parasite,2019,https://boxd.it/2,4.5\n"
            . "2024-01-03,Heat,1995,https://boxd.it/3,0.5\n"
            . "2024-01-04,Unrated,2000,https://boxd.it/4,\n";

        $rows = csvImportParse($csv, 'letterboxd', 'watched');

        $this->assertSame(9, $rows[0]['rating']);
        $this->assertSame(1, $rows[1]['rating']);
        $this->assertNull($rows[2]['rating']);
    }

    public function testLetterboxdWatchlistIgnoresRatings(): void
    {
        $csv = "Date,Name,Year,Letterboxd URI,Rating\n"
            . "2024-01-02,Parasite,2019,https://boxd.it/2,5\n";

        $rows = csvImportParse($csv, 'letterboxd', 'watchlist');

        $this->assertSame('watchlist', $rows[0]['status']);
        $this->assertNull($rows[0]['rating']);
    }

    public function testGoodreadsMapsShelvesAndRatings(): void
    {
        $csv = "Book Id,Title,Author,ISBN,ISBN13,My Rating,Exclusive Shelf\n"
            . "1,\"Dune, Part One\",Frank Herbert,=\"0441013597\",=\"9780441013593\",5,read\n"
            . "2,Sapiens,Yuval Noah Harari,=\"\",=\"\",0,to-read\n"
            . "3,Ulysses,James Joyce,=\"\",=\"\",3,currently-reading\n"
            . "4,Ignored,Nobody,=\"\",=\"\",0,owned\n";

        $rows = csvImportParse($csv, 'goodreads');

        $this->assertCount(3, $rows, 'owned shelf is skipped');
        $this->assertSame('Dune, Part One', $rows[0]['title']);
        $this->assertSame('read', $rows[0]['status']);
        $this->assertSame(10, $rows[0]['rating']);
        $this->assertSame('9780441013593', $rows[0]['isbn'], 'ISBN13 is preferred');
        $this->assertSame('readinglist', $rows[1]['status']);
        $this->assertNull($rows[1]['rating'], 'unrated stays null');
        $this->assertNull($rows[1]['isbn'], 'empty ISBN wrapper is dropped');
        $this->assertSame('readinglist', $rows[2]['status']);
        $this->assertNull($rows[2]['rating'], 'reading list items carry no rating');
    }

    public function testByteOrderMarkIsStripped(): void
    {
        $csv = "\xEF\xBB\xBFDate,Name,Year,Letterboxd URI\n2024-01-02,Parasite,2019,https://boxd.it/2\n";

        $rows = csvImportParse($csv, 'letterboxd');

        $this->assertCount(1, $rows);
        $this->assertSame('Parasite', $rows[0]['title']);
    }

    public function testUnknownSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        csvImportParse("a,b\n1,2\n", 'imdb');
    }

    public function testSanitizeRejectsUnknownStatusAndKind(): void
    {
        $this->assertNull(csvSanitizeRow(['kind' => 'movie', 'title' => 'X', 'status' => 'read']));
        $this->assertNull(csvSanitizeRow(['kind' => 'podcast', 'title' => 'X', 'status' => 'watched']));
        $this->assertNull(csvSanitizeRow(['kind' => 'movie', 'title' => '   ', 'status' => 'watched']));
        $this->assertNull(csvSanitizeRow('not an array'));
    }

    public function testSanitizeClampsRatingAndYear(): void
    {
        $row = csvSanitizeRow(['kind' => 'movie', 'title' => 'Parasite', 'status' => 'watched', 'rating' => 42, 'year' => 1200]);

        $this->assertNotNull($row);
        $this->assertNull($row['rating']);
        $this->assertNull($row['year']);
    }

    public function testSanitizeKeepsValidBookFields(): void
    {
        $row = csvSanitizeRow([
            'kind' => 'book', 'title' => 'Dune', 'status' => 'read', 'rating' => '8',
            'author' => 'Frank Herbert', 'isbn' => '9780441013593',
        ]);

        $this->assertSame(8, $row['rating']);
        $this->assertSame('Frank Herbert', $row['author']);
        $this->assertSame('9780441013593', $row['isbn']);
    }
}
