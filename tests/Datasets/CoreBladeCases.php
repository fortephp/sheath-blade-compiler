<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Tests\Datasets;

use Illuminate\View\Compilers\BladeCompiler;
use ReflectionClass;

final class CoreBladeCases
{
    /**
     * @return array<string, array{source: string, directives: list<string>, generatedDirectives?: list<string>}>
     */
    public static function valid(): array
    {
        $cases = [
            'conditions' => [
                'source' => '@if($show) yes @elseif($other) maybe @else no @endif @unless($hidden) shown @endunless @isset($value) x @endisset @empty($value) x @endempty',
                'directives' => ['if', 'elseif', 'else', 'endif', 'unless', 'endunless', 'isset', 'endisset', 'empty', 'endempty'],
            ],
            'authentication and guests' => [
                'source' => "@auth yes @elseauth('admin') admin @endauth @guest('web') yes @elseguest no @endguest",
                'directives' => ['auth', 'elseauth', 'endauth', 'guest', 'elseguest', 'endguest'],
            ],
            'environment conditions' => [
                'source' => "@env(['local', 'testing']) local @endenv @production prod @endproduction",
                'directives' => ['env', 'endenv', 'production', 'endproduction'],
            ],
            'section and stack conditions' => [
                'source' => "@hasSection('title') yes @endif @sectionMissing('aside') missing @endif @hasStack('scripts') yes @endif",
                'directives' => ['hasSection', 'sectionMissing', 'hasStack', 'endif'],
            ],
            'authorization' => [
                'source' => "@can('edit') yes @elsecan('view') view @endcan @cannot('delete') yes @elsecannot('archive') archive @endcannot @canany(['edit']) yes @elsecanany(['view']) view @endcanany",
                'directives' => ['can', 'elsecan', 'endcan', 'cannot', 'elsecannot', 'endcannot', 'canany', 'elsecanany', 'endcanany'],
            ],
            'switch' => [
                'source' => '@switch($value) @case(1) one @break @case(2) two @break @default other @endswitch',
                'directives' => ['switch', 'case', 'break', 'default', 'endswitch'],
            ],
            'once blocks' => [
                'source' => "@once once @endonce @once('stable-id') named @endonce",
                'directives' => ['once', 'endonce'],
            ],
            'boolean attributes' => [
                'source' => '@bool(true) @checked(true) @selected(false) @disabled(false) @readonly(false) @required(true)',
                'directives' => ['bool', 'checked', 'selected', 'disabled', 'readonly', 'required'],
            ],
            'foreach control flow' => [
                'source' => '@foreach($items as $item) @continue($item === null) {{ $item }} @break($item === false) @endforeach',
                'directives' => ['foreach', 'continue', 'break', 'endforeach'],
            ],
            'forelse' => [
                'source' => '@forelse($items as $item) {{ $item }} @empty none @endforelse',
                'directives' => ['forelse', 'empty', 'endforelse'],
            ],
            'for and while loops' => [
                'source' => '@for($i = 0; $i < 2; $i++) @continue(1) @endfor @while($ready) @break(1) @endwhile',
                'directives' => ['for', 'continue', 'endfor', 'while', 'break', 'endwhile'],
            ],
            'layout inheritance' => [
                'source' => "@extends('layout') @extendsFirst(['tenant', 'layout']) @yield('content')",
                'directives' => ['extends', 'extendsFirst', 'yield'],
            ],
            'section terminators' => [
                'source' => <<<'BLADE'
                    @section('parent') @parent @endsection
                    @section('shown') body @show
                    @section('appended') body @append
                    @section('overwritten') body @overwrite
                    @section('stopped') body @stop
                    @section('inline', 'value')
                    BLADE,
                'directives' => ['section', 'parent', 'endsection', 'show', 'append', 'overwrite', 'stop'],
            ],
            'includes' => [
                'source' => <<<'BLADE'
                    @include('partial') @includeIf('partial') @includeWhen(true, 'partial') @includeUnless(false, 'partial') @includeFirst(['one', 'two']) @includeIsolated('partial') @each('row', $items, 'item')
                    BLADE,
                'directives' => ['include', 'includeIf', 'includeWhen', 'includeUnless', 'includeFirst', 'includeIsolated', 'each'],
            ],
            'component directives' => [
                'source' => "@component('card', ['title' => 'T']) @slot('header') H @endslot Body @endcomponent @componentFirst(['card']) Body @endcomponentFirst",
                'directives' => ['component', 'slot', 'endslot', 'endcomponent', 'componentFirst', 'endcomponentFirst'],
            ],
            'component data' => [
                'source' => "@props(['title' => 'Default']) @aware(['color' => 'gray'])",
                'directives' => ['props', 'aware'],
            ],
            'component tags' => [
                'source' => '<x-dynamic-component :component="$component" :value="$value" />',
                'directives' => ['component', 'endComponentClass'],
                'generatedDirectives' => ['component', 'endComponentClass'],
            ],
            'stacks' => [
                'source' => <<<'BLADE'
                    @stack('scripts')
                    @push('scripts') one @endpush
                    @pushOnce('scripts', 'id') two @endPushOnce
                    @prepend('scripts') three @endprepend
                    @prependOnce('scripts', 'id') four @endprependOnce
                    BLADE,
                'directives' => ['stack', 'push', 'endpush', 'pushOnce', 'endpushOnce', 'prepend', 'endprepend', 'prependOnce', 'endprependOnce'],
            ],
            'conditional stacks' => [
                'source' => "@pushIf(true, 'scripts') x @elsePushIf(false, 'other') y @elsePush('fallback') z @endPushIf",
                'directives' => ['pushIf', 'elsePushIf', 'elsePush', 'endPushIf'],
            ],
            'fragments' => [
                'source' => "@fragment('name') fragment @endfragment",
                'directives' => ['fragment', 'endfragment'],
            ],
            'validation context and session' => [
                'source' => "@error('email') bad @enderror @context('canonical') yes @endcontext @session('status') ok @endsession",
                'directives' => ['error', 'enderror', 'context', 'endcontext', 'session', 'endsession'],
            ],
            'translation output' => [
                'source' => "@lang('messages.welcome') @choice('messages.apples', 2) @lang(['messages.welcome', 'fallback']) block @endlang",
                'directives' => ['lang', 'choice', 'endlang'],
            ],
            'helpers' => [
                'source' => "@csrf @method('PATCH') @dd() @dump() @vite @vite(['resources/js/app.js']) @viteReactRefresh",
                'directives' => ['csrf', 'method', 'dd', 'dump', 'vite', 'viteReactRefresh'],
            ],
            'json javascript class and style' => [
                'source' => "@json(['ok' => true]) @js(['ok' => true]) @class(['active' => true]) @style(['color: red' => true])",
                'directives' => ['json', 'js', 'class', 'style'],
            ],
            'services and variables' => [
                'source' => <<<'BLADE'
                    @inject('service', 'App\Service') @unset($value)
                    BLADE,
                'directives' => ['inject', 'unset'],
            ],
            'imports' => [
                'source' => "@use('App\\Models\\User') @use('function App\\Support\\helper') @use('const App\\Support\\VALUE') @use('App\\Support\\{One, Two}')",
                'directives' => ['use'],
            ],
            'inline PHP directive' => [
                'source' => '@php($value = 1)',
                'directives' => ['php'],
            ],
            'PHP block directive' => [
                'source' => '@php $items = [1, 2, 3]; @endphp',
                'directives' => ['php'],
            ],
            'echo forms' => [
                'source' => '{{ $escaped }} {!! $raw !!} {{{ $legacy }}} @{{ clientExpression }}',
                'directives' => [],
            ],
            'Blade comments and verbatim blocks' => [
                'source' => '{{-- @if($broken +) {{ invalid }} @endif --}} @verbatim {{ $client }} @if($client) @endverbatim',
                'directives' => [],
            ],
            'escaped directives' => [
                'source' => '@@if($client) @@endif',
                'directives' => [],
            ],
            'native PHP tags' => [
                'source' => '<?php $value = 1; ?> <?= $value ?>',
                'directives' => [],
            ],
        ];

        if (self::compilerSupportsFonts()) {
            $cases['helpers']['source'] .= " @fonts(['Inter'])";
            $cases['helpers']['directives'][] = 'fonts';
        }

        return $cases;
    }

    /**
     * @return array<string, array{source: string, detail: string}>
     */
    public static function invalid(): array
    {
        $cases = [
            'if expression' => ['source' => '@if($value +) yes @endif', 'detail' => 'syntax error'],
            'missing conditional terminator' => ['source' => '@if(true) yes', 'detail' => 'syntax error'],
            'stray conditional terminator' => ['source' => '@endif', 'detail' => 'syntax error'],
            'mismatched conditional terminator' => ['source' => '@if(true) @foreach($items as $item) value @endif @endforeach', 'detail' => 'syntax error'],
            'duplicate else branch' => ['source' => '@if(true) yes @else no @else never @endif', 'detail' => 'syntax error'],
            'elseif expression' => ['source' => '@if(true) yes @elseif($value +) no @endif', 'detail' => 'syntax error'],
            'unless expression' => ['source' => '@unless($value +) yes @endunless', 'detail' => 'syntax error'],
            'isset expression' => ['source' => '@isset($value +) yes @endisset', 'detail' => 'syntax error'],
            'authentication guard' => ['source' => "@auth('web' +) yes @endauth", 'detail' => 'syntax error'],
            'environment expression' => ['source' => "@env('local' +) yes @endenv", 'detail' => 'syntax error'],
            'section condition' => ['source' => "@hasSection('title' +) yes @endif", 'detail' => 'syntax error'],
            'authorization expression' => ['source' => "@can('edit' +) yes @endcan", 'detail' => 'syntax error'],
            'duplicate switch default' => ['source' => '@switch($value) @default one @default two @endswitch', 'detail' => 'syntax error'],
            'case expression' => ['source' => '@switch($value) @case($value +) value @endswitch', 'detail' => 'syntax error'],
            'once identifier' => ['source' => '@once($id +) value @endonce', 'detail' => 'syntax error'],
            'boolean attribute expression' => ['source' => '@checked($value +)', 'detail' => 'syntax error'],
            'malformed foreach' => ['source' => '@foreach($items as) value @endforeach', 'detail' => 'Malformed @foreach statement'],
            'malformed forelse' => ['source' => '@forelse($items as) value @empty none @endforelse', 'detail' => 'Malformed @forelse statement'],
            'for expression' => ['source' => '@for($i = 0; $i <; $i++) value @endfor', 'detail' => 'syntax error'],
            'while expression' => ['source' => '@while($ready +) value @endwhile', 'detail' => 'syntax error'],
            'empty branch outside forelse' => ['source' => '@empty value @endforelse', 'detail' => 'syntax error'],
            'break outside loop' => ['source' => '@break', 'detail' => "'break' not in the 'loop' or 'switch' context"],
            'continue outside loop' => ['source' => '@continue', 'detail' => "'continue' not in the 'loop' or 'switch' context"],
            'break exceeds loop nesting' => ['source' => '@for($i = 0; $i < 1; $i++) @break(2) @endfor', 'detail' => "Cannot 'break' 2 levels"],
            'layout expression' => ['source' => '@extends($layout +)', 'detail' => 'syntax error'],
            'section expression' => ['source' => '@section($name +) value @endsection', 'detail' => 'syntax error'],
            'yield expression' => ['source' => '@yield($name +)', 'detail' => 'syntax error'],
            'include expression' => ['source' => '@include($view +)', 'detail' => 'syntax error'],
            'each expression' => ['source' => '@each($view +)', 'detail' => 'syntax error'],
            'component expression' => ['source' => '@component($name +) value @endcomponent', 'detail' => 'syntax error'],
            'slot expression' => ['source' => '@slot($name +) value @endslot', 'detail' => 'syntax error'],
            'props expression' => ['source' => "@props(['title' => ])", 'detail' => 'syntax error'],
            'aware expression' => ['source' => "@aware(['color' => ])", 'detail' => 'syntax error'],
            'conditional stack arguments' => ['source' => '@pushIf(true) value @endPushIf', 'detail' => '@pushIf expects a condition and stack name'],
            'fragment expression' => ['source' => '@fragment($name +) value @endfragment', 'detail' => 'syntax error'],
            'error expression' => ['source' => "@error('email' +) value @enderror", 'detail' => 'syntax error'],
            'context expression' => ['source' => "@context('key' +) value @endcontext", 'detail' => 'syntax error'],
            'session expression' => ['source' => "@session('key' +) value @endsession", 'detail' => 'syntax error'],
            'translation expression' => ['source' => "@lang('message' +)", 'detail' => 'syntax error'],
            'choice expression' => ['source' => "@choice('message', 1 +)", 'detail' => 'syntax error'],
            'method expression' => ['source' => "@method('PATCH' +)", 'detail' => 'syntax error'],
            'Vite expression' => ['source' => "@vite(['app.js' => ])", 'detail' => 'syntax error'],
            'JSON expression' => ['source' => "@json(['value' => ])", 'detail' => 'syntax error'],
            'JavaScript expression' => ['source' => "@js(['value' => ])", 'detail' => 'syntax error'],
            'class expression' => ['source' => "@class(['enabled' => ])", 'detail' => 'syntax error'],
            'style expression' => ['source' => "@style(['color: red' => ])", 'detail' => 'syntax error'],
            'inject arguments' => ['source' => "@inject('service')", 'detail' => '@inject expects a variable name and service'],
            'unset expression' => ['source' => '@unset($value +)', 'detail' => 'syntax error'],
            'empty import' => ['source' => "@use('')", 'detail' => 'syntax error'],
            'duplicate import alias' => ['source' => "@use('First\\Thing', 'Duplicate') @use('Second\\Thing', 'Duplicate')", 'detail' => 'already in use'],
            'inline PHP expression' => ['source' => '@php($value +)', 'detail' => 'syntax error'],
            'PHP block syntax' => ['source' => '@php $value = ; @endphp', 'detail' => 'syntax error'],
            'native PHP tag syntax' => ['source' => '<?php $value = ; ?>', 'detail' => 'syntax error'],
        ];

        if (self::compilerSupportsFonts()) {
            $cases['font expression'] = ['source' => "@fonts(['Inter' => ])", 'detail' => 'syntax error'];
        }

        return $cases;
    }

    /** @return list<string> */
    public static function coveredDirectives(): array
    {
        $directives = [];

        foreach (self::valid() as $case) {
            array_push($directives, ...$case['directives']);
        }

        $directives = array_values(array_unique(array_map(strtolower(...), $directives)));
        sort($directives);

        return $directives;
    }

    private static function compilerSupportsFonts(): bool
    {
        return (new ReflectionClass(BladeCompiler::class))->hasMethod('compileFonts');
    }
}
