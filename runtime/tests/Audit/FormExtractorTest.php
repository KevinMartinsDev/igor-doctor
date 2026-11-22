<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\FormExtractor;
use IgorDoctor\Runtime\Audit\Probe;
use PHPUnit\Framework\TestCase;

final class FormExtractorTest extends TestCase
{
    private const PAGE = <<<'HTML'
        <form method="post" action="save?step=1">
            <input type="hidden" name="form[_token]" value="abc">
            <input type="text" name="form[name]">
            <input type="email" name="form[email]">
            <input type="number" name="form[age]" value="42">
            <input type="checkbox" name="form[terms]" value="1" checked>
            <input type="checkbox" name="form[news]" value="1">
            <input type="radio" name="form[size]" value="s"><input type="radio" name="form[size]" value="m">
            <select name="form[country]"><option value="">Choose</option><option value="fr">France</option><option value="de">Germany</option></select>
            <textarea name="form[bio]"></textarea>
            <input type="file" name="form[avatar]">
            <input type="text" name="form[locked]" disabled>
            <button type="submit" name="form[save]">Save</button>
        </form>
        <form action="https://elsewhere.test/login" method="post"><input name="q"></form>
        <form action="/search"><input type="search" name="q"></form>
        HTML;

    public function testEachFieldGetsAValueItsTypeAccepts(): void
    {
        $forms = FormExtractor::forms(self::PAGE, '/account/edit');

        self::assertCount(2, $forms);
        self::assertSame(['action' => '/account/save?step=1', 'method' => 'POST'], ['action' => $forms[0]['action'], 'method' => $forms[0]['method']]);
        parse_str(str_replace(Probe::MARKER, 'M', $forms[0]['body']), $fields);
        self::assertSame(['_token' => 'abc', 'name' => 'M', 'email' => 'M@example.test', 'age' => '42', 'terms' => '1', 'country' => '{fr|de|fr}', 'bio' => 'M', 'size' => '{s|m|s}'], $fields['form']);
        self::assertSame(['action' => '/search', 'method' => 'GET', 'body' => 'q='.Probe::MARKER], $forms[1]);
    }

    public function testTheHiddenFieldsAreReadFromTheFormOfTheAction(): void
    {
        self::assertSame(['form[_token]' => 'abc'], FormExtractor::hiddenFields(self::PAGE, '/account/save?step=1&igor=x', '/account/edit'));
        self::assertSame([], FormExtractor::hiddenFields(self::PAGE, '/other', '/account/edit'));
    }

    public function testAPostedBodyBecomesTheRequestParametersWithTheFreshHiddenFields(): void
    {
        $probe = new Probe('form', '/account/save', 'POST', ['Content-Type' => Probe::FORM_TYPE], 'form%5B_token%5D=old&form%5Bname%5D='.Probe::MARKER.'&form%5Bsize%5D={s|m|s}', [], '/account/edit');

        $request = $probe->request('igor2xabc', 2, ['form[_token]' => 'fresh']);

        self::assertSame(['_token' => 'fresh', 'name' => 'igor2xabc', 'size' => 'm'], $request->request->all('form'));
        self::assertSame(['m'], $probe->choices(2));
    }
}
