import { useEffect, useState } from "react";
import { TextAnimate } from "@/components/ui/text-animate";

const motivationalQuotes = [
    "Your only limit is your mind. Push past the doubt; your potential is infinite. Start today, not tomorrow.",
    "Consistency is quiet magic. Small, daily efforts compound into massive success. Trust the process and keep building.",
    "Failure is just feedback. Learn from the fall, adjust your path, and rise stronger. Progress is not linear.",
    "The best way to predict the future is to create it. Stop wishing and start doing. Action overcomes anxiety.",
    "Don't wait for permission to shine. Be your own biggest cheerleader and step into the spotlight you deserve.",
    "Gratitude fuels grit. Appreciate what you have while working for what you want. A thankful heart is powerful.",
    "Choose courage over comfort. Growth lives outside your familiar zone. Embrace the challenge and expand your world.",
    "Be kinder to yourself. Self-compassion is not a weakness; it's the foundation of unstoppable resilience.",
    "Ideas are cheap. Execution is everything. Stop planning perfectly and start doing imperfectly. Get to work!",
    "Your mindset is the master key. Change your thoughts, and you change your reality. Believe you can, and you will.",
    "Let your passion be your purpose. When you love what you do, the journey becomes its own reward. Find your fire.",
    "Don't compare your Chapter 1 to someone else's Chapter 20. Focus on your page, your pace, and your story.",
    "Embrace the discomfort of change. That feeling is the sound of your spirit growing stronger. Keep moving forward.",
    "Do one thing every day that scares you. Building bravery is a muscle. Flex it daily for a fearless life.",
    "Strive for progress, not perfection. Small steps forward are still wins. Celebrate the journey, not just the goal."
];

interface AnimatedQuotesProps {
    className?: string;
    interval?: number;
}

export function AnimatedQuotes({ className, interval = 8000 }: AnimatedQuotesProps) {
    const [currentQuote, setCurrentQuote] = useState(0);
    const [key, setKey] = useState(0);

    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentQuote((prev) => (prev + 1) % motivationalQuotes.length);
            setKey((prev) => prev + 1);
        }, interval);

        return () => clearInterval(timer);
    }, [interval]);

    return (
        <div className="relative">
            <svg className="absolute -left-4 -top-2 w-8 h-8 text-white/30 gap-5" fill="currentColor" viewBox="0 0 32 32">
                <path d="M10 8c-3.3 0-6 2.7-6 6v10h8V14h-6c0-2.2 1.8-4 4-4V8zm14 0c-3.3 0-6 2.7-6 6v10h8V14h-6c0-2.2 1.8-4 4-4V8z" />
            </svg>
            <TextAnimate
                key={key}
                animation="blurIn"
                as="p"
                className={`italic font-serif ${className}`}
                duration={1.3}
            >
                {`"${motivationalQuotes[currentQuote]}"`}
            </TextAnimate>
            <svg className="absolute -right-4 -bottom-2 w-8 h-8 text-white/30 rotate-180 gap-3" fill="currentColor" viewBox="0 0 32 32">
                <path d="M10 8c-3.3 0-6 2.7-6 6v10h8V14h-6c0-2.2 1.8-4 4-4V8zm14 0c-3.3 0-6 2.7-6 6v10h8V14h-6c0-2.2 1.8-4 4-4V8z" />
            </svg>
        </div>
    );
}
