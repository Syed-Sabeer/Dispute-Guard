@extends('layouts.app')
@section('title','Edit Email Template')
@section('content')
<s-section heading="{{ str_replace('_',' ',$template->dispute_reason) }} / {{ str_replace('_',' ',$template->shipping_state) }}">
<form data-api-form data-method="PUT" action="/templates/{{ $template->id }}">
<label><input type="checkbox" name="enabled" @checked($template->enabled)>Enable this combination</label>
<label>Subject<input name="subject" required maxlength="200" value="{{ $template->subject }}"></label>
<label>Body<textarea name="body" required maxlength="20000">{{ $template->body }}</textarea></label>
<s-text>Basic paragraph, line break, bold, italic, and list formatting is supported. HTML attributes and links are removed; tracking URLs appear as text.</s-text>
<div class="actions"><button>Save template</button><button data-action="/templates/{{ $template->id }}/preview" data-method="POST">Preview</button>
<button data-action="/templates/{{ $template->id }}/restore" data-method="POST" data-confirm="Restore the default subject and body? Your edits will be replaced.">Restore default</button></div>
</form></s-section>
<s-section heading="Available variables"><p>@foreach(\App\Services\Email\TemplateRenderer::VARIABLES as $variable)<code>&#123;&#123;{{ $variable }}&#125;&#125;</code> @endforeach</p></s-section>
<s-section heading="Template preview"><h3 id="preview-subject">Select Preview to review formatting. Customer and order fields are filled when an email is sent.</h3><div class="preview" id="preview-body"></div></s-section>
@endsection
