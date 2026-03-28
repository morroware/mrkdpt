<?php

declare(strict_types=1);

function register_landing_page_routes(Router $router, LandingPageRepository $pages): void
{
    $router->get('/api/landing-pages', fn() => json_response(['items' => $pages->all()]));

    $router->post('/api/landing-pages', function () use ($pages) {
        $data = request_json();
        if (empty($data['title'])) {
            json_response(['error' => 'title is required'], 400);
            return;
        }
        json_response(['item' => $pages->create($data)], 201);
    });

    $router->get('/api/landing-pages/{id}', function (array $params) use ($pages) {
        $page = $pages->find((int)$params['id']);
        $page ? json_response(['item' => $page]) : json_response(['error' => 'Not found'], 404);
    });

    $router->patch('/api/landing-pages/{id}', function (array $params) use ($pages) {
        $data = request_json();
        $page = $pages->update((int)$params['id'], $data);
        $page ? json_response(['item' => $page]) : json_response(['error' => 'Not found'], 404);
    });

    $router->delete('/api/landing-pages/{id}', function (array $params) use ($pages) {
        $pages->delete((int)$params['id'])
            ? json_response(['deleted' => true])
            : json_response(['error' => 'Not found'], 404);
    });

    // Section templates for the builder
    $router->get('/api/landing-pages/section-templates', function () {
        json_response(['items' => [
            [
                'type' => 'features',
                'label' => 'Features Grid',
                'description' => 'Highlight your key features or services',
                'default' => [
                    'type' => 'features',
                    'heading' => 'Why Choose Us',
                    'items' => [
                        ['icon' => '&#9733;', 'title' => 'Quality Service', 'description' => 'We deliver exceptional results every time'],
                        ['icon' => '&#9889;', 'title' => 'Fast Turnaround', 'description' => 'Quick delivery without compromising quality'],
                        ['icon' => '&#128176;', 'title' => 'Great Value', 'description' => 'Competitive pricing for premium service'],
                    ],
                ],
            ],
            [
                'type' => 'testimonials',
                'label' => 'Testimonials',
                'description' => 'Show customer reviews and social proof',
                'default' => [
                    'type' => 'testimonials',
                    'heading' => 'What Our Customers Say',
                    'items' => [
                        ['quote' => 'Absolutely amazing service! Exceeded all expectations.', 'name' => 'Jane Smith', 'role' => 'Local Business Owner', 'rating' => 5],
                        ['quote' => 'Professional, reliable, and great communication throughout.', 'name' => 'John Doe', 'role' => 'Satisfied Customer', 'rating' => 5],
                    ],
                ],
            ],
            [
                'type' => 'faq',
                'label' => 'FAQ Accordion',
                'description' => 'Answer common questions',
                'default' => [
                    'type' => 'faq',
                    'heading' => 'Frequently Asked Questions',
                    'items' => [
                        ['question' => 'How do I get started?', 'answer' => 'Simply fill out our contact form and we\'ll get back to you within 24 hours.'],
                        ['question' => 'What areas do you serve?', 'answer' => 'We serve the local area and surrounding communities.'],
                        ['question' => 'Do you offer free consultations?', 'answer' => 'Yes! Contact us to schedule your free initial consultation.'],
                    ],
                ],
            ],
            [
                'type' => 'pricing',
                'label' => 'Pricing Table',
                'description' => 'Display your pricing plans',
                'default' => [
                    'type' => 'pricing',
                    'heading' => 'Our Plans',
                    'items' => [
                        ['name' => 'Basic', 'price' => '$29/mo', 'description' => 'Perfect for getting started', 'features' => ['Core features', 'Email support', '1 user'], 'cta_text' => 'Get Started', 'cta_url' => '#form'],
                        ['name' => 'Pro', 'price' => '$79/mo', 'description' => 'Best for growing businesses', 'features' => ['All Basic features', 'Priority support', '5 users', 'Analytics'], 'cta_text' => 'Get Started', 'cta_url' => '#form', 'featured' => true],
                        ['name' => 'Enterprise', 'price' => 'Custom', 'description' => 'For large organizations', 'features' => ['All Pro features', 'Dedicated support', 'Unlimited users', 'Custom integrations'], 'cta_text' => 'Contact Us', 'cta_url' => '#form'],
                    ],
                ],
            ],
            [
                'type' => 'cta',
                'label' => 'Call-to-Action Banner',
                'description' => 'Add a prominent CTA section',
                'default' => [
                    'type' => 'cta',
                    'heading' => 'Ready to Get Started?',
                    'subheading' => 'Join thousands of satisfied customers today.',
                    'cta_text' => 'Get Started Now',
                    'cta_url' => '#form',
                ],
            ],
            [
                'type' => 'text',
                'label' => 'Text Block',
                'description' => 'Add a custom text section',
                'default' => [
                    'type' => 'text',
                    'heading' => 'About Us',
                    'body' => 'Tell your story here. What makes your business unique? Why should customers choose you?',
                ],
            ],
        ]]);
    });
}
