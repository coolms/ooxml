<?php

declare(strict_types=1);

// The platform's code style, the same rules every CoolMS PHP repository is checked against.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php')
    ->notPath('vendor')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Rule sets
        '@Symfony'          => true,
        '@PHP84Migration'   => true,

        // Imports
        // Always use `use` declarations -- never inline FQCNs or backslash-prefixed names.
        'global_namespace_import' => [
            'import_classes'   => true,  // use DateTimeImmutable; not \DateTimeImmutable
            'import_constants' => true,  // use const PHP_INT_MAX; not \PHP_INT_MAX
            'import_functions' => true,  // use function strlen; not \strlen()
        ],
        // Do NOT add backslashes to native function calls (opposite of native_function_invocation).
        // global_namespace_import above handles imports; backslash prefix is never used.
        'native_function_invocation' => false,
        'native_constant_invocation' => false,

        // Arrays
        'array_syntax'             => ['syntax' => 'short'],
        'trim_array_spaces'        => true,
        'no_whitespace_before_comma_in_array' => true,
        'whitespace_after_comma_in_array'     => ['ensure_single_space' => true],

        // Blank lines
        // No decorative blank lines inside methods. Blank lines only before
        // `return` and `throw` when the method has multiple logical steps.
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute',
                'break',
                'case',
                'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                'return',
                'square_brace_block',
                'switch',
                'throw',
                'use',
                'use_trait',
            ],
        ],
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw'],
        ],
        'no_blank_lines_after_class_opening' => true,

        // Class structure
        'class_attributes_separation' => [
            'elements' => [
                'const'        => 'one',
                'method'       => 'one',
                'property'     => 'none',  // no blank lines between properties
                'trait_import' => 'none',
            ],
        ],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'method_public_static',
                'method_public',
                'method_protected_static',
                'method_protected',
                'method_private_static',
                'method_private',
            ],
        ],

        // Operators and spacing
        'concat_space'               => ['spacing' => 'one'],
        'cast_spaces'                => ['space' => 'single'],
        'method_chaining_indentation' => true,
        'binary_operator_spaces'     => [
            'default' => 'single_space',
        ],
        'unary_operator_spaces' => true,

        // Strings
        'single_quote' => true,  // prefer single quotes for plain strings

        // Returns
        'no_useless_return'          => true,
        'simplified_if_return'       => true,
        'no_unreachable_default_argument_value' => true,

        // Strictness
        'declare_strict_types' => true,          // always declare(strict_types=1)
        'strict_comparison'    => true,          // === not ==
        'strict_param'         => true,          // in_array strict mode etc.

        // Casts
        'modernize_types_casting' => true,       // (int) not intval()

        // PHP 8.x idioms
        'use_arrow_functions'           => true, // fn() => instead of function() { return }
        'lambda_not_used_import'        => true,
        'no_useless_nullsafe_operator'  => true,

        // Comments
        // One space before // in inline comments. No alignment padding.
        'single_line_comment_spacing'   => true,  // enforces exactly one space after //
        'no_trailing_whitespace_in_comment' => true,
        'align_multiline_comment'       => false, // do not pad /** ... */ blocks
        'phpdoc_order'                    => true,
        'phpdoc_separation'               => true,
        'phpdoc_trim'                     => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_no_empty_return'          => true,  // remove useless @return void
        'phpdoc_scalar'                   => true,  // int not integer, bool not boolean
        'phpdoc_types'                    => true,
        'phpdoc_var_without_name'         => true,
        'no_superfluous_phpdoc_tags'      => [
            'allow_mixed'         => false,
            'remove_inheritdoc'   => false,
            'allow_unused_params' => false,
        ],

        // Semicolons and trailing commas
        'no_empty_statement'                    => true,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'trailing_comma_in_multiline'           => [
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],

        // Misc
        'no_unused_imports'              => true,
        'ordered_imports'                => [
            'sort_algorithm' => 'alpha',
            'imports_order'  => ['class', 'function', 'const'],
        ],
        'visibility_required'            => ['elements' => ['property', 'method', 'const']],
        'self_accessor'                  => true,
        'no_alias_functions'             => true,
        'function_to_constant'           => true,  // get_class() -> ClassName::class
    ])
    ->setFinder($finder)
;
