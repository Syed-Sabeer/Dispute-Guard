<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\Email\TemplateRenderer;
use PHPUnit\Framework\TestCase;
class TemplateRendererTest extends TestCase
{
    public function test_all_whitelisted_variables_and_missing_variables(): void
    {
        $r = new TemplateRenderer;
        foreach ($r::VARIABLES as $variable) {
            $this->assertSame('safe',$r->render('{{'.$variable.'}}',[$variable=>'safe']));
            $this->assertSame('',$r->render('{{'.$variable.'}}',[]));
        }
        $this->assertSame('',$r->render('{{unknown}}',[]));
    }
    public function test_html_and_code_injection_cannot_execute(): void
    {
        $r = new TemplateRenderer;
        $html = $r->render('<p onclick="evil()">Hello {{customer_name}}</p><script>alert(1)</script><img src=x onerror=evil()><a href="javascript:evil()">click</a>', ['customer_name'=>'<img src=x onerror=evil()>']);
        $this->assertStringNotContainsString('<script',$html);
        $this->assertStringNotContainsString('<img',$html);
        $this->assertStringNotContainsString('onclick=',$html);
        $this->assertStringNotContainsString('<a ',$html);
        $this->assertStringContainsString('&lt;img',$html);
        $this->assertSame('', $r->render('<?php die("oops"); ?>',[]));
        $this->assertSame('Hello there', $r->render("Hello\r\nthere",[],false));
    }
    public function test_no_attributes_survive_malformed_markup(): void
    {
        $r = new TemplateRenderer;
        foreach (['<p style="background:url(javascript:x)">x</p>','<b/onmouseover=evil()>x</b>','<svg><script>evil()</script></svg>'] as $input) {
            $result = $r->sanitize($input);
            $this->assertDoesNotMatchRegularExpression('/<(?:p|b|svg|script)[^>]*[=\/]/i',$result);
        }
    }
}
