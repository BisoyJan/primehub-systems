import { useEffect, useState } from "react";
import { TextAnimate } from "@/components/ui/text-animate";

const motivationalQuotes = [
  "Success is not final, failure is not fatal: it is the courage to continue that counts.",
  "The only way to do great work is to love what you do.",
  "Don't watch the clock; do what it does. Keep going.",
  "The future depends on what you do today.",
  "Believe you can and you're halfway there.",
  "Success is walking from failure to failure with no loss of enthusiasm.",
  "The only limit to our realization of tomorrow is our doubts of today.",
  "Quality is not an act, it is a habit.",
  "The best time to plant a tree was 20 years ago. The second best time is now.",
  "Your work is going to fill a large part of your life, and the only way to be truly satisfied is to do what you believe is great work.",
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
    <TextAnimate
      key={key}
      animation="blurIn"
      as="p"
      className={className}
      duration={1.2}
    >
      {motivationalQuotes[currentQuote]}
    </TextAnimate>
  );
}
