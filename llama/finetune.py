from unsloth import FastLanguageModel
from trl import SFTTrainer
from transformers import TrainingArguments
from datasets import load_dataset
import torch

# 1. Load base model
model, tokenizer = FastLanguageModel.from_pretrained(
    model_name="unsloth/Meta-Llama-3.1-8B-Instruct",
    max_seq_length=2048,
    load_in_4bit=True,       # uses less VRAM
    dtype=None,              # auto-detect
)

# 2. Apply LoRA adapter (only trains ~1% of weights — fast and cheap)
model = FastLanguageModel.get_peft_model(
    model,
    r=16,
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj",
                    "gate_proj", "up_proj", "down_proj"],
    lora_alpha=16,
    lora_dropout=0,
    bias="none",
    use_gradient_checkpointing="unsloth",
    random_state=42,
)

# 3. Load your dataset
dataset = load_dataset("json", data_files="medicine_chatbot_dataset.jsonl", split="train")
print(f"Loaded {len(dataset)} training examples")

# 4. Format conversations into the LLaMA chat template
def format_conversation(example):
    messages = example["messages"]
    text = tokenizer.apply_chat_template(
        messages,
        tokenize=False,
        add_generation_prompt=False,
    )
    return {"text": text}

dataset = dataset.map(format_conversation)

# 5. Train
trainer = SFTTrainer(
    model=model,
    tokenizer=tokenizer,
    train_dataset=dataset,
    dataset_text_field="text",
    max_seq_length=2048,
    dataset_num_proc=2,
    args=TrainingArguments(
        per_device_train_batch_size=2,
        gradient_accumulation_steps=4,
        num_train_epochs=5,        # 5 passes over your 30 examples
        warmup_steps=10,
        learning_rate=2e-4,
        fp16=not torch.cuda.is_bf16_supported(),
        bf16=torch.cuda.is_bf16_supported(),
        logging_steps=5,
        output_dir="./checkpoints",
        save_strategy="epoch",
        optim="adamw_8bit",
        weight_decay=0.01,
        lr_scheduler_type="cosine",
        seed=42,
    ),
)

print("Starting training...")
trainer.train()
print("Training complete!")

# 6. Save the fine-tuned model
model.save_pretrained("medicine-chatbot-final")
tokenizer.save_pretrained("medicine-chatbot-final")
print("Model saved to: medicine-chatbot-final/")

# 7. Quick test
FastLanguageModel.for_inference(model)
test_messages = [
    {"role": "system", "content": "Ikaw ang health center chatbot sa barangay."},
    {"role": "user",   "content": "Naa bay paracetamol?"},
]
inputs = tokenizer.apply_chat_template(
    test_messages, tokenize=True,
    add_generation_prompt=True, return_tensors="pt"
).to("cuda")

outputs = model.generate(input_ids=inputs, max_new_tokens=128, temperature=0.2)
print("\nTest response:")
print(tokenizer.decode(outputs[0], skip_special_tokens=True))